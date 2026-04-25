<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use function array_keys;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function strcasecmp;
use function strcmp;
use function strpos;
use function strtoupper;
use function substr;
use function usort;

/**
 * @phpstan-type ResponseCoverageState 'validated'|'skipped'
 * @phpstan-type ResponseCoverage array{
 *     state: ResponseCoverageState,
 *     hits: int,
 *     skipReason?: ?string,
 * }
 * @phpstan-type EndpointCoverage array{
 *     requestReached: bool,
 *     responses: array<string, ResponseCoverage>,
 * }
 * @phpstan-type ResponseRowState 'validated'|'uncovered'|'skipped'
 * @phpstan-type ResponseRow array{
 *     statusKey: string,
 *     contentTypeKey: string,
 *     state: ResponseRowState,
 *     hits: int,
 *     skipReason: ?string,
 * }
 * @phpstan-type EndpointState 'all-covered'|'partial'|'uncovered'|'request-only'
 * @phpstan-type EndpointSummary array{
 *     endpoint: string,
 *     method: string,
 *     path: string,
 *     operationId: ?string,
 *     state: EndpointState,
 *     requestReached: bool,
 *     responses: list<ResponseRow>,
 *     coveredResponseCount: int,
 *     skippedResponseCount: int,
 *     totalResponseCount: int,
 *     unexpectedObservations: list<array{statusKey: string, contentTypeKey: string}>,
 * }
 * @phpstan-type CoverageResult array{
 *     endpoints: list<EndpointSummary>,
 *     endpointTotal: int,
 *     endpointFullyCovered: int,
 *     endpointPartial: int,
 *     endpointUncovered: int,
 *     endpointRequestOnly: int,
 *     responseTotal: int,
 *     responseCovered: int,
 *     responseSkipped: int,
 *     responseUncovered: int,
 * }
 */
final class OpenApiCoverageTracker
{
    /** Wildcard sentinel for "any / no content-type" — used when a recording predates */
    /** content-type lookup (skipped responses) and when a spec response has no `content` block. */
    public const ANY_CONTENT_TYPE = '*';

    /**
     * Per-(spec, endpoint) coverage state. Endpoint key is `"{METHOD} {path}"`.
     *
     * Each (statusKey, contentTypeKey) pair is stored under
     * `"{statusKey}:{contentTypeKey}"` and tracks monotonic state — validated
     * > skipped, never demoted — so observation order across a suite does not
     * matter. `hits` increments on each recording. `skipReason` carries the
     * pattern that triggered the skip (latest recording wins).
     *
     * statusKey is the validator's matchedStatusCode (the literal HTTP status
     * for skipped, or the spec response key for validated/failed). Matching
     * against spec range keys (`5XX`, `default`) happens at compute time via
     * {@see self::statusKeyMatches()}.
     *
     * @var array<string, array<string, EndpointCoverage>>
     */
    private static array $covered = [];

    /** Static-only utility — no instances. */
    private function __construct() {}

    /**
     * Mark an endpoint as request-side reached. Used by request validators
     * which see a path/method but no response. Idempotent.
     */
    public static function recordRequest(
        string $specName,
        string $method,
        string $path,
    ): void {
        $key = self::endpointKey($method, $path);
        self::$covered[$specName][$key] ??= ['requestReached' => false, 'responses' => []];
        self::$covered[$specName][$key]['requestReached'] = true;
    }

    /**
     * Record a response observation. `statusKey` is the spec key when the
     * validator matched a declared response (e.g. `"200"`), or the literal
     * HTTP status string when the response was skipped before lookup
     * (e.g. `"503"` matched the `5\d\d` skip pattern). `contentTypeKey` is
     * the spec media-type key (with the spec author's original casing) when
     * a content lookup happened, or null for skipped / 204 / non-JSON-only
     * responses — stored under {@see self::ANY_CONTENT_TYPE}.
     *
     * `validated` states win over `skipped` for the same (status, content)
     * pair across multiple recordings; `hits` accumulates.
     */
    public static function recordResponse(
        string $specName,
        string $method,
        string $path,
        string $statusKey,
        ?string $contentTypeKey,
        bool $schemaValidated,
        ?string $skipReason = null,
    ): void {
        $endpointKey = self::endpointKey($method, $path);
        $contentKey = $contentTypeKey ?? self::ANY_CONTENT_TYPE;
        $responseKey = $statusKey . ':' . $contentKey;

        self::$covered[$specName][$endpointKey] ??= ['requestReached' => false, 'responses' => []];
        $existing = self::$covered[$specName][$endpointKey]['responses'][$responseKey] ?? null;

        if ($existing === null) {
            self::$covered[$specName][$endpointKey]['responses'][$responseKey] = [
                'state' => $schemaValidated ? 'validated' : 'skipped',
                'hits' => 1,
                'skipReason' => $schemaValidated ? null : $skipReason,
            ];

            return;
        }

        $existing['hits']++;
        if ($schemaValidated) {
            // Promote skipped → validated; once validated, stay validated.
            $existing['state'] = 'validated';
            $existing['skipReason'] = null;
        } elseif ($existing['state'] === 'skipped' && $skipReason !== null) {
            // Latest skipReason wins so the renderer surfaces the most recent
            // skip pattern (typically all-the-same in practice; but we don't
            // want to hide a per-test override).
            $existing['skipReason'] = $skipReason;
        }

        self::$covered[$specName][$endpointKey]['responses'][$responseKey] = $existing;
    }

    public static function reset(): void
    {
        self::$covered = [];
    }

    /**
     * Returns true when any record (request or response) exists for the given
     * spec. The PHPUnit extension uses this to gate the "no coverage to report"
     * short-circuit so the report block only renders when at least one test
     * exercised a spec.
     */
    public static function hasAnyCoverage(string $specName): bool
    {
        return (self::$covered[$specName] ?? []) !== [];
    }

    /**
     * Diagnostic accessor: which `"METHOD path"` keys have any recorded
     * activity (request reached or any response observation), grouped by spec.
     * Intended for tests and CLI tooling that only need an "endpoint touched"
     * predicate; richer shape is available via {@see self::computeCoverage()}.
     *
     * @return array<string, array<string, true>>
     */
    public static function getCovered(): array
    {
        $external = [];

        foreach (self::$covered as $spec => $endpoints) {
            foreach ($endpoints as $endpointKey => $entry) {
                if ($entry['requestReached'] === false && $entry['responses'] === []) {
                    continue;
                }
                $external[$spec][$endpointKey] = true;
            }
        }

        return $external;
    }

    /**
     * @return CoverageResult
     */
    public static function computeCoverage(string $specName): array
    {
        $spec = OpenApiSpecLoader::load($specName);
        $recordedEndpoints = self::$covered[$specName] ?? [];
        $endpoints = [];

        $endpointFullyCovered = 0;
        $endpointPartial = 0;
        $endpointUncovered = 0;
        $endpointRequestOnly = 0;
        $responseTotal = 0;
        $responseCovered = 0;
        $responseSkipped = 0;

        $declaredEndpoints = self::collectDeclaredEndpoints($spec);

        foreach ($declaredEndpoints as $declared) {
            $endpointKey = $declared['endpoint'];
            $recorded = $recordedEndpoints[$endpointKey] ?? null;
            $rows = self::buildResponseRows($declared['responses'], $recorded['responses'] ?? []);
            $unexpected = self::collectUnexpectedObservations($declared['responses'], $recorded['responses'] ?? []);

            $coveredCount = 0;
            $skippedCount = 0;
            foreach ($rows as $row) {
                if ($row['state'] === 'validated') {
                    $coveredCount++;
                } elseif ($row['state'] === 'skipped') {
                    $skippedCount++;
                }
            }
            $totalCount = count($rows);

            $requestReached = $recorded['requestReached'] ?? false;
            $hasAnyResponseObservation = ($recorded['responses'] ?? []) !== [];
            $state = self::deriveEndpointState(
                totalDeclared: $totalCount,
                covered: $coveredCount,
                skipped: $skippedCount,
                requestReached: $requestReached,
                hasAnyResponseObservation: $hasAnyResponseObservation,
            );

            match ($state) {
                'all-covered' => $endpointFullyCovered++,
                'partial' => $endpointPartial++,
                'uncovered' => $endpointUncovered++,
                'request-only' => $endpointRequestOnly++,
            };

            $responseTotal += $totalCount;
            $responseCovered += $coveredCount;
            $responseSkipped += $skippedCount;

            $endpoints[] = [
                'endpoint' => $endpointKey,
                'method' => $declared['method'],
                'path' => $declared['path'],
                'operationId' => $declared['operationId'],
                'state' => $state,
                'requestReached' => $requestReached,
                'responses' => $rows,
                'coveredResponseCount' => $coveredCount,
                'skippedResponseCount' => $skippedCount,
                'totalResponseCount' => $totalCount,
                'unexpectedObservations' => $unexpected,
            ];
        }

        return [
            'endpoints' => $endpoints,
            'endpointTotal' => count($endpoints),
            'endpointFullyCovered' => $endpointFullyCovered,
            'endpointPartial' => $endpointPartial,
            'endpointUncovered' => $endpointUncovered,
            'endpointRequestOnly' => $endpointRequestOnly,
            'responseTotal' => $responseTotal,
            'responseCovered' => $responseCovered,
            'responseSkipped' => $responseSkipped,
            'responseUncovered' => $responseTotal - $responseCovered - $responseSkipped,
        ];
    }

    private static function endpointKey(string $method, string $path): string
    {
        return strtoupper($method) . ' ' . $path;
    }

    /**
     * Walk the spec's `paths` map and yield one entry per declared
     * (method, path) operation, listing all (statusKey, contentTypeKey)
     * pairs declared for it. A response with no `content` block contributes
     * a single `(statusKey, '*')` pair so 204-style responses appear in
     * coverage instead of being silently omitted.
     *
     * @param array<string, mixed> $spec
     *
     * @return list<array{
     *     endpoint: string,
     *     method: string,
     *     path: string,
     *     operationId: ?string,
     *     responses: list<array{statusKey: string, contentTypeKey: string}>,
     * }>
     */
    private static function collectDeclaredEndpoints(array $spec): array
    {
        /** @var array<string, mixed> $paths */
        $paths = is_array($spec['paths'] ?? null) ? $spec['paths'] : [];
        $declared = [];

        foreach ($paths as $path => $methods) {
            if (!is_array($methods)) {
                continue;
            }

            foreach ($methods as $method => $operation) {
                $upper = strtoupper((string) $method);
                if (!in_array($upper, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    continue;
                }
                if (!is_array($operation)) {
                    continue;
                }

                $operationId = is_string($operation['operationId'] ?? null) ? $operation['operationId'] : null;
                $responses = is_array($operation['responses'] ?? null) ? $operation['responses'] : [];

                $responsePairs = [];
                foreach ($responses as $statusKey => $responseSpec) {
                    $statusKeyStr = (string) $statusKey;
                    if (!is_array($responseSpec)) {
                        continue;
                    }
                    $content = is_array($responseSpec['content'] ?? null) ? $responseSpec['content'] : null;

                    if ($content === null || $content === []) {
                        $responsePairs[] = ['statusKey' => $statusKeyStr, 'contentTypeKey' => self::ANY_CONTENT_TYPE];

                        continue;
                    }

                    foreach (array_keys($content) as $contentTypeKey) {
                        $responsePairs[] = ['statusKey' => $statusKeyStr, 'contentTypeKey' => (string) $contentTypeKey];
                    }
                }

                $declared[] = [
                    'endpoint' => self::endpointKey($upper, $path),
                    'method' => $upper,
                    'path' => $path,
                    'operationId' => $operationId,
                    'responses' => $responsePairs,
                ];
            }
        }

        // Stable, predictable order: by endpoint key (method + path) so reports
        // are diff-friendly across runs.
        usort($declared, static fn(array $a, array $b): int => strcmp($a['endpoint'], $b['endpoint']));

        return $declared;
    }

    /**
     * For each declared (statusKey, contentTypeKey) pair on an endpoint,
     * compute the resolved state by reconciling against any recordings.
     * Validated wins over skipped; skipped wins over uncovered.
     *
     * Reconciliation pairs each recorded status to declared statuses via
     * exact match first, then via range matching (`5XX`/`5xx`/`default`).
     * Recorded `*` content matches any declared content-type; otherwise
     * matching is case-insensitive on the spec key.
     *
     * @param list<array{statusKey: string, contentTypeKey: string}> $declaredResponses
     * @param array<string, ResponseCoverage> $recordedResponses
     *
     * @return list<ResponseRow>
     */
    private static function buildResponseRows(array $declaredResponses, array $recordedResponses): array
    {
        $rows = [];

        foreach ($declaredResponses as $declared) {
            $bestState = 'uncovered';
            $hits = 0;
            $skipReason = null;

            foreach ($recordedResponses as $recordedKey => $entry) {
                [$recordedStatus, $recordedContent] = self::splitResponseKey($recordedKey);

                if (!self::statusKeyMatches($declared['statusKey'], $recordedStatus)) {
                    continue;
                }
                if (!self::contentTypeMatches($declared['contentTypeKey'], $recordedContent)) {
                    continue;
                }

                if ($entry['state'] === 'validated') {
                    $bestState = 'validated';
                    $hits += $entry['hits'];
                    $skipReason = null;

                    continue;
                }

                if ($bestState === 'uncovered') {
                    $bestState = 'skipped';
                    $hits = $entry['hits'];
                    $skipReason = $entry['skipReason'] ?? null;

                    continue;
                }
                if ($bestState === 'skipped') {
                    $hits += $entry['hits'];
                    if (($entry['skipReason'] ?? null) !== null) {
                        $skipReason = $entry['skipReason'];
                    }
                }
            }

            $rows[] = [
                'statusKey' => $declared['statusKey'],
                'contentTypeKey' => $declared['contentTypeKey'],
                'state' => $bestState,
                'hits' => $hits,
                'skipReason' => $skipReason,
            ];
        }

        // Sort within an endpoint: statusKey ascending, contentTypeKey ascending,
        // with `*` last so concrete content-types stay grouped.
        usort($rows, static function (array $a, array $b): int {
            $statusCmp = strcmp($a['statusKey'], $b['statusKey']);
            if ($statusCmp !== 0) {
                return $statusCmp;
            }
            if ($a['contentTypeKey'] === self::ANY_CONTENT_TYPE && $b['contentTypeKey'] !== self::ANY_CONTENT_TYPE) {
                return 1;
            }
            if ($b['contentTypeKey'] === self::ANY_CONTENT_TYPE && $a['contentTypeKey'] !== self::ANY_CONTENT_TYPE) {
                return -1;
            }

            return strcasecmp($a['contentTypeKey'], $b['contentTypeKey']);
        });

        return $rows;
    }

    /**
     * Recorded entries that don't reconcile to any spec declaration are
     * surfaced separately so a test exercising an undocumented response
     * (e.g. real `503` against a spec with no `5XX` declaration) is visible
     * without inflating "uncovered" counts.
     *
     * @param list<array{statusKey: string, contentTypeKey: string}> $declaredResponses
     * @param array<string, ResponseCoverage> $recordedResponses
     *
     * @return list<array{statusKey: string, contentTypeKey: string}>
     */
    private static function collectUnexpectedObservations(array $declaredResponses, array $recordedResponses): array
    {
        $unexpected = [];

        foreach ($recordedResponses as $recordedKey => $_entry) {
            [$recordedStatus, $recordedContent] = self::splitResponseKey($recordedKey);

            $matched = false;
            foreach ($declaredResponses as $declared) {
                if (
                    self::statusKeyMatches($declared['statusKey'], $recordedStatus) &&
                    self::contentTypeMatches($declared['contentTypeKey'], $recordedContent)
                ) {
                    $matched = true;

                    break;
                }
            }

            if (!$matched) {
                $unexpected[] = ['statusKey' => $recordedStatus, 'contentTypeKey' => $recordedContent];
            }
        }

        // Stable order for diff-friendly output.
        usort($unexpected, static function (array $a, array $b): int {
            $cmp = strcmp($a['statusKey'], $b['statusKey']);

            return $cmp !== 0 ? $cmp : strcasecmp($a['contentTypeKey'], $b['contentTypeKey']);
        });

        return $unexpected;
    }

    /**
     * Determine the endpoint-level state from declared / covered / skipped
     * counts. `request-only` only when no responses are declared in spec
     * (or none were observed) and the request side fired — a rare but
     * legitimate "auto-validate-request without auto-assert" pattern.
     */
    /**
     * @return EndpointState
     */
    private static function deriveEndpointState(
        int $totalDeclared,
        int $covered,
        int $skipped,
        bool $requestReached,
        bool $hasAnyResponseObservation,
    ): string {
        if ($totalDeclared === 0) {
            // No response definitions in spec — this happens for operations
            // declared without a `responses` block. Treat as request-only when
            // the endpoint was reached (request hook fired) so it's not lost.
            return $requestReached || $hasAnyResponseObservation ? 'request-only' : 'uncovered';
        }

        if ($covered === 0 && $skipped === 0) {
            return $requestReached || $hasAnyResponseObservation ? 'request-only' : 'uncovered';
        }

        if ($covered === $totalDeclared) {
            return 'all-covered';
        }

        return 'partial';
    }

    /**
     * Spec key matches recorded key when:
     * - exact case-insensitive match
     * - `default` matches anything
     * - `1XX`/`2XX`/`3XX`/`4XX`/`5XX` (case-insensitive) matches a literal
     *   3-digit status whose first digit equals the leading digit
     *
     * Matching is intentionally directional: `$specKey` is what the spec
     * declares, `$recordedKey` is what the recording stored. So
     * `statusKeyMatches('5XX', '503')` is true; the reverse is also true
     * since exact spec key matches happen via the same code path when both
     * sides are equal.
     */
    private static function statusKeyMatches(string $specKey, string $recordedKey): bool
    {
        if (strcasecmp($specKey, $recordedKey) === 0) {
            return true;
        }

        if (strcasecmp($specKey, 'default') === 0) {
            return true;
        }

        // Range key on the spec side: e.g. spec="5XX" recorded="503".
        if (preg_match('/^([1-5])[xX]{2}$/', $specKey, $matches) === 1 &&
            preg_match('/^[1-5][0-9]{2}$/', $recordedKey) === 1 &&
            $recordedKey[0] === $matches[1]
        ) {
            return true;
        }

        // Range key on the recorded side is unusual (validators record literal
        // statuses) but support symmetry for tests/external callers.
        if (preg_match('/^([1-5])[xX]{2}$/', $recordedKey, $matches) === 1 &&
            preg_match('/^[1-5][0-9]{2}$/', $specKey) === 1 &&
            $specKey[0] === $matches[1]
        ) {
            return true;
        }

        return false;
    }

    /**
     * `*` (the wildcard sentinel) on either side matches anything; otherwise
     * comparison is case-insensitive against the spec author's literal key.
     */
    private static function contentTypeMatches(string $specContentType, string $recordedContentType): bool
    {
        if ($specContentType === self::ANY_CONTENT_TYPE || $recordedContentType === self::ANY_CONTENT_TYPE) {
            return true;
        }

        return strcasecmp($specContentType, $recordedContentType) === 0;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitResponseKey(string $key): array
    {
        $colonPos = strpos($key, ':');
        if ($colonPos === false) {
            return [$key, self::ANY_CONTENT_TYPE];
        }

        return [substr($key, 0, $colonPos), substr($key, $colonPos + 1)];
    }
}
