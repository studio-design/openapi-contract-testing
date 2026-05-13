<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Strict;

use const E_USER_WARNING;

use Studio\OpenApiContractTesting\Exception\InvalidOpenApiSpecException;
use Studio\OpenApiContractTesting\Exception\SpecFileNotFoundException;
use Studio\OpenApiContractTesting\Exception\StrictRequiredDriftException;
use Studio\OpenApiContractTesting\PHPUnit\CoverageReportSubscriber;
use Studio\OpenApiContractTesting\Schema\EnumDriftAsserter;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;

use function array_diff;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function is_array;
use function is_string;
use function ksort;
use function sort;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strpos;
use function strtolower;
use function strtoupper;
use function substr;
use function trigger_error;

/**
 * Compare {@see StrictRequiredTracker} observations against each spec's
 * declared `required` arrays, at every walked nesting level. Surfaces
 * endpoints whose response bodies consistently include keys the spec marks
 * as optional — a sign that the spec *under-describes* the implementation.
 *
 * The companion of {@see EnumDriftAsserter}:
 *  - EnumDriftAsserter: PHP enum cases vs spec `enum:` arrays (static).
 *  - StrictRequiredAsserter: runtime response body keys vs spec `required`
 *    arrays (per-test-run aggregate), walked through nested objects and
 *    array elements.
 *
 * `allOf` is walked at every level when collecting `required`. `anyOf` /
 * `oneOf` are intentionally NOT walked — they are disjunctions and there is
 * no safe AND-semantic for "required" across them. The collected `required`
 * at such a node is therefore `[]`, which makes *every* always-present key
 * at that node surface as drift. Consult `docs/strict-required.md`
 * "Known limitations" before relying on those constructs.
 *
 * Observations whose `(method, path, status, content-type)` does not resolve
 * to a response schema in the spec are silently skipped from drift reporting
 * — that is the coverage tracker's responsibility, not this asserter's. A
 * NOTE is emitted at run end if any such mismatches were observed so users
 * can tell "no drift" apart from "no schema to compare against".
 */
final class StrictRequiredAsserter
{
    /** Static-only utility — no instances. */
    private function __construct() {}

    /**
     * Detect any under-description drift and either throw
     * {@see StrictRequiredDriftException} (in {@see StrictRequiredMode::Fail})
     * or emit `E_USER_WARNING` (in {@see StrictRequiredMode::Warn}). Off mode
     * short-circuits to a no-op.
     *
     * @throws StrictRequiredDriftException when drift is detected and the
     *                                      mode is {@see StrictRequiredMode::Fail}
     */
    public static function assertNoDrift(StrictRequiredMode $mode): void
    {
        if ($mode === StrictRequiredMode::Off) {
            return;
        }

        $drifting = self::detectAll($mode);

        if ($drifting === []) {
            return;
        }

        $message = self::renderMessage($drifting, $mode === StrictRequiredMode::Fail);

        if ($mode === StrictRequiredMode::Fail) {
            throw new StrictRequiredDriftException($drifting, $message);
        }

        trigger_error($message, E_USER_WARNING);
    }

    /**
     * Compute reports for every recorded `(spec, endpoint, status, content-type,
     * schemaPointer)` cell that **actually drifts** — i.e. whose intersection
     * of observed keys contains at least one key not declared in the matching
     * schema's `required` array at that pointer. Clean cells are filtered out
     * because their `missingFromRequired` is empty by definition; surfacing
     * them would be pure noise.
     *
     * `Off` mode returns `[]` rather than walking observations so the
     * extension can call this from coverage paths without paying the cost
     * when the feature is disabled.
     *
     * @return list<StrictRequiredReport>
     */
    public static function detectAll(StrictRequiredMode $mode): array
    {
        return self::analyse($mode)['reports'];
    }

    /**
     * Diagnostic accessor: groups whose observation was recorded but whose
     * spec response schema could not be resolved at run end. Empty under
     * the normal happy path (validator records only on Success, so every
     * group should round-trip back to a schema).
     *
     * A non-empty result is bug-level: it means the validator's match key
     * disagrees with the asserter's lookup, or a `$ref` resolved to an
     * unexpected shape, or a spec file was unlinked mid-run. The subscriber
     * surfaces these as a NOTE so users can tell "no drift" apart from
     * "no schema to compare against".
     *
     * @return list<string> human-readable identifiers in the form
     *                      `"specName :: METHOD path :: statusKey:contentTypeKey"`
     *
     * @internal Consumed by {@see CoverageReportSubscriber}.
     */
    public static function detectUnresolvedGroups(StrictRequiredMode $mode): array
    {
        return self::analyse($mode)['unresolved'];
    }

    /**
     * Diagnostic accessor: observation pointers that fell under a schema
     * node where descent could not proceed safely — `anyOf` / `oneOf` /
     * scalar / malformed nodes. `required` has no AND-semantic at a
     * disjunction node, so producing "add to `required`" drift advice for
     * these would be actively misleading; the subscriber surfaces them as
     * a NOTE instead.
     *
     * Observed pointers under a property that the spec does NOT declare
     * (e.g. impl returns a field never mentioned in `properties`) still
     * surface as drift — only the disjunction / unresolved-schema case
     * lands here.
     *
     * @return list<string> identifiers in the form
     *                      `"specName :: METHOD path :: statusKey:contentTypeKey:pointer (reason)"`
     *
     * @internal Consumed by {@see CoverageReportSubscriber}.
     */
    public static function detectUnwalkableNodes(StrictRequiredMode $mode): array
    {
        return self::analyse($mode)['unwalkable'];
    }

    /**
     * Render the diagnostic block describing every drifting endpoint.
     *
     * @param list<StrictRequiredReport> $reports
     *
     * @internal Exposed only so the PHPUnit subscriber can reuse the same
     *           block format when invoking the asserter at ExecutionFinished
     *           without re-firing the `trigger_error` / throw path that
     *           {@see self::assertNoDrift()} would use.
     */
    public static function renderMessage(array $reports, bool $isFatal): string
    {
        $severity = $isFatal ? 'FATAL' : 'WARNING';
        $count = count($reports);
        $header = sprintf(
            "[OpenAPI Strict Required] %s: %d endpoint response(s) have always-present fields missing from `required`.\n",
            $severity,
            $count,
        );

        $bodies = array_map(
            static function (StrictRequiredReport $r): string {
                $missingList = implode("\n", array_map(
                    static fn(string $k): string => '      - ' . $k,
                    $r->missingFromRequired,
                ));

                return sprintf(
                    "  %s %s  %s  %s:%s\n    Observed in %d response(s); the following keys appeared every time but are not declared in `required`:\n%s",
                    $r->method,
                    $r->path,
                    $r->statusKey,
                    $r->contentTypeKey,
                    $r->schemaPointer,
                    $r->hits,
                    $missingList,
                );
            },
            $reports,
        );

        $footer = "\nAction: add these fields to the response schema's `required` array, or set `strict_required = off` if intentional.\nConfiguration: phpunit.xml <parameter name=\"strict_required\">warn|fail|off</parameter>";

        return $header . "\n" . implode("\n\n", $bodies) . "\n" . $footer;
    }

    /**
     * @return array{reports: list<StrictRequiredReport>, unresolved: list<string>, unwalkable: list<string>}
     */
    private static function analyse(StrictRequiredMode $mode): array
    {
        if ($mode === StrictRequiredMode::Off) {
            return ['reports' => [], 'unresolved' => [], 'unwalkable' => []];
        }

        $reports = [];
        $unresolved = [];
        $unwalkable = [];
        foreach (StrictRequiredTracker::recordedSpecs() as $specName) {
            $spec = self::reportsForSpec($specName);
            foreach ($spec['reports'] as $report) {
                $reports[] = $report;
            }
            foreach ($spec['unresolved'] as $u) {
                $unresolved[] = $u;
            }
            foreach ($spec['unwalkable'] as $u) {
                $unwalkable[] = $u;
            }
        }
        sort($unresolved);
        sort($unwalkable);

        return ['reports' => $reports, 'unresolved' => $unresolved, 'unwalkable' => $unwalkable];
    }

    /**
     * @return array{reports: list<StrictRequiredReport>, unresolved: list<string>, unwalkable: list<string>}
     */
    private static function reportsForSpec(string $specName): array
    {
        $observations = StrictRequiredTracker::getObservations($specName);
        if ($observations === []) {
            return ['reports' => [], 'unresolved' => [], 'unwalkable' => []];
        }

        try {
            $spec = OpenApiSpecLoader::load($specName);
        } catch (InvalidOpenApiSpecException|SpecFileNotFoundException $e) {
            // A spec file unlinked or rewritten between bootstrap and
            // ExecutionFinished is not the asserter's job to escalate
            // (coverage reporting handles that channel) — but if we
            // silently dropped the observations, the user would see "no
            // drift" with no clue that the spec itself is the cause.
            // Degrade to an unresolved NOTE per observation, carrying the
            // load failure message so the diagnostic points at the spec
            // not at strict_required.
            $unresolvedAll = [];
            foreach ($observations as $endpointKey => $responses) {
                foreach (array_keys($responses) as $responseKey) {
                    $unresolvedAll[] = sprintf(
                        '%s :: %s :: %s (spec failed to load: %s)',
                        $specName,
                        $endpointKey,
                        $responseKey,
                        $e->getMessage(),
                    );
                }
            }

            return ['reports' => [], 'unresolved' => $unresolvedAll, 'unwalkable' => []];
        }

        $reports = [];
        $unresolved = [];
        $unwalkable = [];
        foreach ($observations as $endpointKey => $responses) {
            [$method, $path] = self::splitEndpointKey($endpointKey);
            foreach ($responses as $responseKey => $row) {
                [$statusKey, $contentTypeKey] = self::splitResponseKey($responseKey);
                $schemaNode = self::resolveResponseSchema(
                    $spec,
                    $method,
                    $path,
                    $statusKey,
                    $contentTypeKey,
                );
                if ($schemaNode === null) {
                    // Schema does not exist for this observation. The
                    // validator only records on Success, so reaching this
                    // branch means either a $ref resolved to an unexpected
                    // shape or the path matcher and the asserter disagree
                    // on the canonical key — both are bug-level. Record
                    // the group so the subscriber can surface a NOTE; do
                    // not produce a drift report (`required = []` would
                    // falsely flag every always-present key).
                    $unresolved[] = sprintf('%s :: %s :: %s', $specName, $endpointKey, $responseKey);

                    continue;
                }

                $analysis = self::collectRequiredByPointer($schemaNode);
                $walked = $analysis['walked'];
                $disjunctions = $analysis['disjunctions'];

                // Sort the observed pointers so generated reports are
                // deterministic — useful for snapshot-style assertions and
                // stable CI diffs.
                $pointers = $row['pointers'];
                ksort($pointers);

                foreach ($pointers as $pointer => $alwaysPresent) {
                    $disjunction = self::findCoveringDisjunction($pointer, $disjunctions);
                    if ($disjunction !== null) {
                        // The spec node at (or above) this pointer is a
                        // disjunction (`anyOf` / `oneOf`); the "add to
                        // required" advice does not apply. Surface as a
                        // NOTE separately from drift reports.
                        $unwalkable[] = sprintf(
                            '%s :: %s :: %s:%s (%s at %s)',
                            $specName,
                            $endpointKey,
                            $responseKey,
                            $pointer,
                            $disjunction['reason'],
                            $disjunction['pointer'] === '' ? '<root>' : $disjunction['pointer'],
                        );

                        continue;
                    }

                    $specRequired = $walked[$pointer] ?? [];
                    $missing = array_values(array_diff($alwaysPresent, $specRequired));
                    if ($missing === []) {
                        continue;
                    }
                    sort($missing);

                    $reports[] = new StrictRequiredReport(
                        specName: $specName,
                        method: $method,
                        path: $path,
                        statusKey: $statusKey,
                        contentTypeKey: $contentTypeKey,
                        missingFromRequired: $missing,
                        hits: $row['hits'],
                        schemaPointer: $pointer,
                    );
                }
            }
        }

        return ['reports' => $reports, 'unresolved' => $unresolved, 'unwalkable' => $unwalkable];
    }

    /**
     * Return the disjunction descriptor covering an observed pointer, or
     * `null` if none. An ancestor matches when the observed pointer equals
     * the disjunction's pointer OR descends from it via a `/` or `[`
     * separator boundary. A disjunction with an empty pointer (`""`) marks
     * "root schema is unwalkable" and covers every observation.
     *
     * @param list<array{pointer: string, reason: string}> $disjunctions
     *
     * @return null|array{pointer: string, reason: string}
     */
    private static function findCoveringDisjunction(string $pointer, array $disjunctions): ?array
    {
        foreach ($disjunctions as $d) {
            $dp = $d['pointer'];
            if ($dp === '') {
                return $d;
            }
            if ($pointer === $dp) {
                return $d;
            }
            if (str_starts_with($pointer, $dp . '/') || str_starts_with($pointer, $dp . '[')) {
                return $d;
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitEndpointKey(string $endpointKey): array
    {
        $spacePos = strpos($endpointKey, ' ');
        if ($spacePos === false) {
            return [strtoupper($endpointKey), '/'];
        }

        return [
            strtoupper(substr($endpointKey, 0, $spacePos)),
            substr($endpointKey, $spacePos + 1),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitResponseKey(string $responseKey): array
    {
        $colonPos = strpos($responseKey, ':');
        if ($colonPos === false) {
            return [$responseKey, StrictRequiredTracker::ANY_CONTENT_TYPE];
        }

        return [
            substr($responseKey, 0, $colonPos),
            substr($responseKey, $colonPos + 1),
        ];
    }

    /**
     * Locate the response schema dict for `(method, path, statusKey,
     * contentTypeKey)`. Returns `null` when any segment of the descent does
     * not resolve.
     *
     * @param array<string, mixed> $spec
     *
     * @return null|array<string, mixed>
     */
    private static function resolveResponseSchema(
        array $spec,
        string $method,
        string $path,
        string $statusKey,
        string $contentTypeKey,
    ): ?array {
        $lowerMethod = strtolower($method);
        $operation = $spec['paths'][$path][$lowerMethod] ?? null;
        if (!is_array($operation)) {
            return null;
        }
        $responses = $operation['responses'] ?? null;
        if (!is_array($responses)) {
            return null;
        }
        $response = $responses[$statusKey] ?? null;
        if (!is_array($response)) {
            return null;
        }
        $content = $response['content'] ?? null;
        if (!is_array($content)) {
            return null;
        }
        $entry = $content[$contentTypeKey] ?? null;
        if (!is_array($entry)) {
            return null;
        }
        $schema = $entry['schema'] ?? null;
        if (!is_array($schema)) {
            return null;
        }

        return $schema;
    }

    /**
     * Descend the response schema producing two parallel maps:
     *  - `walked`: `pointer => required-keys` for each object node reached.
     *    `allOf` branches are unioned at every level.
     *  - `disjunctions`: pointers where descent stopped because the node is
     *    `anyOf` / `oneOf` (no safe AND-semantic for `required` across
     *    disjunctions). The caller uses this to surface observations under
     *    these pointers as NOTE rather than misleading drift advice.
     *
     * If the root schema itself is unwalkable, the disjunction list carries
     * an empty-pointer entry meaning "every observation is unwalkable."
     *
     * @param array<string, mixed> $schema
     *
     * @return array{walked: array<string, list<string>>, disjunctions: list<array{pointer: string, reason: string}>}
     */
    private static function collectRequiredByPointer(array $schema): array
    {
        $walked = [];
        $disjunctions = [];
        $rootShape = self::inferShape($schema);
        if ($rootShape === null) {
            // Root schema is itself unwalkable (anyOf/oneOf/scalar/empty).
            // Use an empty-pointer disjunction so any observed pointer
            // matches; the body could be either object- or array-shaped.
            $disjunctions[] = [
                'pointer' => '',
                'reason' => self::disjunctionReason($schema),
            ];

            return ['walked' => $walked, 'disjunctions' => $disjunctions];
        }
        // Root-array schemas use bare `[*]` for their element pointer
        // (matching the walker's root-list convention); object roots start
        // at `/`.
        $rootPointer = $rootShape === 'array' ? '' : '/';
        self::descendSchema($schema, $rootPointer, $walked, $disjunctions);

        return ['walked' => $walked, 'disjunctions' => $disjunctions];
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, list<string>> $walked
     * @param list<array{pointer: string, reason: string}> $disjunctions
     */
    private static function descendSchema(array $schema, string $pointer, array &$walked, array &$disjunctions): void
    {
        $type = self::inferShape($schema);
        if ($type === 'object') {
            $walked[$pointer] = self::collectRequiredFromSchema($schema);
            foreach (self::collectPropertyBranches($schema) as $propName => $propSchema) {
                $childPointer = self::appendProperty($pointer, $propName);
                self::descendSchema($propSchema, $childPointer, $walked, $disjunctions);
            }

            return;
        }
        if ($type === 'array') {
            $items = self::collectItemsSchema($schema);
            if ($items !== null) {
                self::descendSchema($items, $pointer . '[*]', $walked, $disjunctions);
            }

            return;
        }
        // type is null — scalar leaf, anyOf / oneOf, or malformed node.
        // For disjunctions specifically, surface the descent stop as a
        // disjunction entry so the caller can emit a NOTE rather than
        // misleading drift advice. Scalar / empty nodes are silently
        // skipped — an observed pointer landing there really is "spec
        // doesn't model this" and surfacing as drift is appropriate.
        $reason = self::disjunctionReason($schema);
        if ($reason !== null) {
            $disjunctions[] = ['pointer' => $pointer, 'reason' => $reason];
        }
    }

    /**
     * Classify a schema node that {@see self::inferShape()} reported as
     * non-descendable. Returns the disjunction kind if applicable (so the
     * caller can emit a NOTE), `null` for scalar / empty leaves (which
     * legitimately do not contribute to `required` semantics).
     *
     * @param array<string, mixed> $schema
     */
    private static function disjunctionReason(array $schema): ?string
    {
        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            return 'anyOf';
        }
        if (isset($schema['oneOf']) && is_array($schema['oneOf'])) {
            return 'oneOf';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return null|'array'|'object'
     */
    private static function inferShape(array $schema): ?string
    {
        $type = $schema['type'] ?? null;
        if ($type === 'object' || isset($schema['properties'])) {
            return 'object';
        }
        if ($type === 'array' || isset($schema['items'])) {
            return 'array';
        }
        // allOf-only schema with no explicit type: treat as object if any
        // branch declares object-shape. Matches OpenAPI's "object inferred
        // from properties / required" convention.
        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $branch) {
                if (is_array($branch) && (
                    ($branch['type'] ?? null) === 'object' ||
                    isset($branch['properties']) ||
                    isset($branch['required'])
                )) {
                    return 'object';
                }
                if (is_array($branch) && (
                    ($branch['type'] ?? null) === 'array' || isset($branch['items'])
                )) {
                    return 'array';
                }
            }
        }

        return null;
    }

    /**
     * Union of `required` arrays at this schema node, walking `allOf`
     * branches so nested descent inherits AND-semantic `required`
     * composition.
     *
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    private static function collectRequiredFromSchema(array $schema): array
    {
        $collected = [];
        if (isset($schema['required']) && is_array($schema['required'])) {
            foreach ($schema['required'] as $entry) {
                if (is_string($entry)) {
                    $collected[] = $entry;
                }
            }
        }
        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $branch) {
                if (is_array($branch)) {
                    foreach (self::collectRequiredFromSchema($branch) as $key) {
                        $collected[] = $key;
                    }
                }
            }
        }

        return array_values(array_unique($collected));
    }

    /**
     * Yield `propertyName => propertySchema` from this schema node plus any
     * `allOf` branches' properties. Later branches override earlier ones —
     * matches the OpenAPI composition convention.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, array<string, mixed>>
     */
    private static function collectPropertyBranches(array $schema): array
    {
        $out = [];
        $properties = $schema['properties'] ?? null;
        if (is_array($properties)) {
            foreach ($properties as $name => $sub) {
                if (is_string($name) && is_array($sub)) {
                    $out[$name] = $sub;
                }
            }
        }
        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $branch) {
                if (!is_array($branch)) {
                    continue;
                }
                foreach (self::collectPropertyBranches($branch) as $name => $sub) {
                    $out[$name] = $sub;
                }
            }
        }

        return $out;
    }

    /**
     * Resolve the `items` schema for an array node, looking through `allOf`
     * branches if the direct `items` field is absent.
     *
     * @param array<string, mixed> $schema
     *
     * @return null|array<string, mixed>
     */
    private static function collectItemsSchema(array $schema): ?array
    {
        $items = $schema['items'] ?? null;
        if (is_array($items)) {
            return $items;
        }
        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $branch) {
                if (!is_array($branch)) {
                    continue;
                }
                $branchItems = self::collectItemsSchema($branch);
                if ($branchItems !== null) {
                    return $branchItems;
                }
            }
        }

        return null;
    }

    private static function appendProperty(string $pointer, string $propertyName): string
    {
        $escaped = str_replace('~', '~0', $propertyName);
        $escaped = str_replace('/', '~1', $escaped);
        $escaped = str_replace('[*]', '[~*]', $escaped);

        if ($pointer === '/') {
            return '/' . $escaped;
        }

        return $pointer . '/' . $escaped;
    }
}
