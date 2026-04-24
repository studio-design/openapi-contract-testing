<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use const FILTER_VALIDATE_INT;
use const PHP_INT_MAX;

use InvalidArgumentException;
use LogicException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use stdClass;

use function array_is_list;
use function array_key_exists;
use function array_key_first;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function filter_var;
use function get_debug_type;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_numeric;
use function is_scalar;
use function is_string;
use function preg_match;
use function rawurldecode;
use function sprintf;
use function str_ends_with;
use function strstr;
use function strtolower;
use function trim;

final class OpenApiRequestValidator
{
    /**
     * Per OpenAPI 3.x: "If `in` is `header` and the `name` field is `Accept`,
     * `Content-Type` or `Authorization`, the parameter definition SHALL be
     * ignored." These are controlled by content negotiation and security
     * schemes; surfacing them through parameter validation would duplicate
     * (and often disagree with) the framework's own handling.
     */
    private const RESERVED_HEADER_NAMES = ['accept', 'content-type', 'authorization'];

    /** @var array<string, OpenApiPathMatcher> */
    private array $pathMatchers = [];
    private Validator $opisValidator;
    private ErrorFormatter $errorFormatter;

    public function __construct(
        private readonly int $maxErrors = 20,
    ) {
        if ($this->maxErrors < 0) {
            throw new InvalidArgumentException(
                sprintf('maxErrors must be 0 (unlimited) or a positive integer, got %d.', $this->maxErrors),
            );
        }

        $resolvedMaxErrors = $this->maxErrors === 0 ? PHP_INT_MAX : $this->maxErrors;
        $this->opisValidator = new Validator(
            max_errors: $resolvedMaxErrors,
            stop_at_first_error: $resolvedMaxErrors === 1,
        );
        $this->errorFormatter = new ErrorFormatter();
    }

    /**
     * Validate an incoming request against the OpenAPI spec.
     *
     * Composes path-parameter, query-parameter, header-parameter, security,
     * and request-body validation plus any spec-level errors surfaced while
     * collecting merged parameters, and returns a single result. Errors from
     * all sources are accumulated so a single test run surfaces every
     * contract drift the request exhibits.
     *
     * @param array<string, mixed> $queryParams parsed query string (string|array<string> per key)
     * @param array<array-key, mixed> $headers request headers (string|array<string> per key, case-insensitive name match; non-string keys are silently dropped)
     * @param array<string, mixed> $cookies request cookies (string values per key). Used for apiKey security schemes with `in: cookie`. Caller is expected to pass framework-parsed cookies (e.g. Laravel's `$request->cookies->all()`) — this validator does not parse a `Cookie` header.
     */
    public function validate(
        string $specName,
        string $method,
        string $requestPath,
        array $queryParams,
        array $headers,
        mixed $requestBody,
        ?string $contentType = null,
        array $cookies = [],
    ): OpenApiValidationResult {
        $spec = OpenApiSpecLoader::load($specName);

        $version = OpenApiVersion::fromSpec($spec);

        /** @var string[] $specPaths */
        $specPaths = array_keys($spec['paths'] ?? []);
        $matcher = $this->getPathMatcher($specName, $specPaths);
        $matched = $matcher->matchWithVariables($requestPath);

        if ($matched === null) {
            return OpenApiValidationResult::failure([
                "No matching path found in '{$specName}' spec for: {$requestPath}",
            ]);
        }

        $matchedPath = $matched['path'];
        $pathVariables = $matched['variables'];

        $lowerMethod = strtolower($method);
        /** @var array<string, mixed> $pathSpec */
        $pathSpec = $spec['paths'][$matchedPath] ?? [];

        if (!isset($pathSpec[$lowerMethod])) {
            return OpenApiValidationResult::failure([
                "Method {$method} not defined for path {$matchedPath} in '{$specName}' spec.",
            ], $matchedPath);
        }

        /** @var array<string, mixed> $operation */
        $operation = $pathSpec[$lowerMethod];

        // Collect merged path/operation parameters once so path + query validation
        // share a single view of the spec and malformed-entry errors are surfaced
        // only once.
        [$parameters, $specErrors] = $this->collectParameters($method, $matchedPath, $pathSpec, $operation);

        $pathErrors = $this->validatePathParameters(
            $method,
            $matchedPath,
            $parameters,
            $pathVariables,
            $version,
        );
        $queryErrors = $this->validateQueryParameters(
            $method,
            $matchedPath,
            $parameters,
            $queryParams,
            $version,
        );
        $headerErrors = $this->validateHeaderParameters(
            $method,
            $matchedPath,
            $parameters,
            $headers,
            $version,
        );
        $securityErrors = $this->validateSecurity(
            $method,
            $matchedPath,
            $spec,
            $operation,
            $headers,
            $queryParams,
            $cookies,
        );
        $bodyErrors = $this->validateRequestBody(
            $specName,
            $method,
            $matchedPath,
            $operation,
            $requestBody,
            $contentType,
            $version,
        );

        $errors = [...$specErrors, ...$pathErrors, ...$queryErrors, ...$headerErrors, ...$securityErrors, ...$bodyErrors];

        if ($errors === []) {
            return OpenApiValidationResult::success($matchedPath);
        }

        return OpenApiValidationResult::failure($errors, $matchedPath);
    }

    /**
     * Recursively convert PHP arrays to stdClass objects, matching the
     * behaviour of json_decode(json_encode($data)) without the intermediate
     * JSON string allocation.
     */
    private static function toObject(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($value === [] || array_is_list($value)) {
            /** @var list<mixed> $value */
            foreach ($value as $i => $item) {
                $value[$i] = self::toObject($item);
            }

            return $value;
        }

        $object = new stdClass();
        foreach ($value as $key => $item) {
            $object->{$key} = self::toObject($item);
        }

        return $object;
    }

    /**
     * Pick the first primitive type from an OAS 3.1 multi-type declaration,
     * skipping `null`. Returns `null` if no usable string type is found.
     *
     * @param array<int|string, mixed> $types
     */
    private static function firstPrimitiveType(array $types): ?string
    {
        foreach ($types as $candidate) {
            if (is_string($candidate) && $candidate !== 'null') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Coerce a URL-sourced string to int.
     *
     * `filter_var(FILTER_VALIDATE_INT)` is too permissive for contract testing:
     * it accepts leading/trailing whitespace (e.g. "5 " → 5) and a leading
     * sign prefix ("+5" → 5). Combined with rawurldecode these laundering
     * behaviours would silently pass non-canonical URLs — real servers
     * typically reject them, creating silent drift between the test harness
     * and production. Pre-filter with a strict canonical-integer regex:
     * optional leading `-`, then either `0` or a digit string without a
     * leading zero. Anything else falls through unchanged so opis can
     * report a meaningful type error.
     *
     * Overflow is still handled by `filter_var` returning `false` for
     * values exceeding PHP_INT_MAX/MIN.
     */
    private static function coerceToInt(string $value): int|string
    {
        if (preg_match('/^-?(0|[1-9]\d*)$/', $value) !== 1) {
            return $value;
        }

        $result = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($result) ? $result : $value;
    }

    /**
     * Scalar-only variant used for path parameters. Path segments arrive as
     * single strings (OpenAPI default `style: simple`) so array handling is
     * never appropriate — a spec declaring `type: array` for a path param
     * would be rejected by opis because the request value is still scalar.
     *
     * @param array<string, mixed> $schema
     */
    private static function coercePrimitiveValue(mixed $value, array $schema): mixed
    {
        $type = $schema['type'] ?? null;

        if (is_array($type)) {
            $type = self::firstPrimitiveType($type);
        }

        return self::coercePrimitiveFromType($value, $type);
    }

    /**
     * Shared scalar coercion: string → int/float/bool when the target type is
     * clean, otherwise the original value passes through so opis can report a
     * meaningful type mismatch.
     */
    private static function coercePrimitiveFromType(mixed $value, mixed $type): mixed
    {
        if (!is_string($value) || !is_string($type)) {
            return $value;
        }

        return match ($type) {
            'integer' => self::coerceToInt($value),
            'number' => is_numeric($value) ? (float) $value : $value,
            'boolean' => match (strtolower($value)) {
                'true' => true,
                'false' => false,
                default => $value,
            },
            default => $value,
        };
    }

    /**
     * Lower-case the keys of the caller-supplied headers map. Non-string keys
     * are skipped — they cannot match any spec name and would cause a
     * TypeError on strtolower(). Values are returned as-is; array/scalar
     * discrimination happens in validateHeaderParameters() so the "how many
     * values" decision is visible at the validation site.
     *
     * When two keys collapse to the same lower-case form (e.g. both
     * `X-Foo` and `x-foo` are present), later entries overwrite earlier ones
     * — HTTP treats these as the same header so the behaviour matches what
     * most frameworks surface to application code.
     *
     * @param array<array-key, mixed> $headers
     *
     * @return array<string, mixed>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (!is_string($name)) {
                continue;
            }

            $normalized[strtolower($name)] = $value;
        }

        return $normalized;
    }

    /**
     * @param string[] $specPaths
     */
    private function getPathMatcher(string $specName, array $specPaths): OpenApiPathMatcher
    {
        return $this->pathMatchers[$specName] ??= new OpenApiPathMatcher($specPaths, OpenApiSpecLoader::getStripPrefixes());
    }

    /**
     * Validate query parameters declared by the matched operation (or
     * inherited from the path-level `parameters` block).
     *
     * Only `style: form` + `explode: true` (the OpenAPI default for `in: query`)
     * is supported. Repeated keys (`?tags=a&tags=b`) are expected to arrive as
     * PHP arrays from the framework. Other styles (`form`+`explode:false`,
     * `pipeDelimited`, `spaceDelimited`) are out of scope.
     *
     * @param list<array<string, mixed>> $parameters pre-collected merged parameters (path + operation level)
     * @param array<string, mixed> $queryParams
     *
     * @return string[]
     */
    private function validateQueryParameters(
        string $method,
        string $matchedPath,
        array $parameters,
        array $queryParams,
        OpenApiVersion $version,
    ): array {
        $errors = [];

        foreach ($parameters as $param) {
            if (($param['in'] ?? null) !== 'query') {
                continue;
            }

            /** @var string $name */
            $name = $param['name'];
            $required = ($param['required'] ?? false) === true;

            // A required parameter with no schema is a clearly malformed spec — surface it
            // rather than silently passing every request. Optional parameters with no schema
            // have nothing to validate, so we let them through (matches the body validator).
            if (!isset($param['schema']) || !is_array($param['schema'])) {
                if ($required) {
                    $errors[] = "[query.{$name}] required parameter has no schema for {$method} {$matchedPath} — cannot validate.";
                }

                continue;
            }

            /** @var array<string, mixed> $schema */
            $schema = $param['schema'];

            $present = array_key_exists($name, $queryParams) && $queryParams[$name] !== null;
            if (!$present) {
                if ($required) {
                    $errors[] = "[query.{$name}] required query parameter is missing.";
                }

                continue;
            }

            $coerced = $this->coerceQueryValue($queryParams[$name], $schema);
            $jsonSchema = OpenApiSchemaConverter::convert($schema, $version, SchemaContext::Request);

            $schemaObject = self::toObject($jsonSchema);
            $dataObject = self::toObject($coerced);

            $result = $this->opisValidator->validate($dataObject, $schemaObject);
            if ($result->isValid()) {
                continue;
            }

            $formatted = $this->errorFormatter->format($result->error());
            foreach ($formatted as $path => $messages) {
                $suffix = $path === '/' ? '' : $path;
                foreach ($messages as $message) {
                    $errors[] = "[query.{$name}{$suffix}] {$message}";
                }
            }
        }

        return $errors;
    }

    /**
     * Validate path parameters declared by the matched operation (or
     * inherited from the path-level `parameters` block) against the values
     * extracted by the path matcher.
     *
     * Path parameters are always required in OpenAPI, so a declared `in: path`
     * entry without a `schema` is treated as a hard spec error rather than a
     * silent pass. Percent-encoded segments are decoded via `rawurldecode()`
     * before type coercion / schema validation — the matcher leaves values
     * raw so encoding policy stays in one place.
     *
     * String-valued `format: uuid | date-time | date | email | ...` is
     * delegated to opis/json-schema's built-in FormatResolver (registered on
     * the `string` type by default). Numeric OAS formats (`int32`, `int64`,
     * `float`, `double`) are advisory-only and are not validated.
     *
     * @param list<array<string, mixed>> $parameters pre-collected merged parameters
     * @param array<string, string> $pathVariables values extracted by OpenApiPathMatcher
     *
     * @return string[]
     */
    private function validatePathParameters(
        string $method,
        string $matchedPath,
        array $parameters,
        array $pathVariables,
        OpenApiVersion $version,
    ): array {
        $errors = [];
        $declared = [];

        foreach ($parameters as $param) {
            if (($param['in'] ?? null) !== 'path') {
                continue;
            }

            /** @var string $name */
            $name = $param['name'];
            $declared[$name] = true;

            // Defensive: every path placeholder in the matched template should have
            // been captured by the regex. A mismatch here means the spec template and
            // the compiled matcher disagree — surface it loudly rather than skipping.
            if (!array_key_exists($name, $pathVariables)) {
                $errors[] = "[path.{$name}] declared in {$method} {$matchedPath} spec but not captured by path matcher.";

                continue;
            }

            if (!isset($param['schema']) || !is_array($param['schema'])) {
                // Path parameters are implicitly required (OpenAPI spec), so a schema-less
                // entry means every value passes — exactly the silent-drift outcome this
                // library exists to prevent.
                $errors[] = "[path.{$name}] parameter has no schema for {$method} {$matchedPath} — cannot validate.";

                continue;
            }

            /** @var array<string, mixed> $schema */
            $schema = $param['schema'];

            $decoded = rawurldecode($pathVariables[$name]);
            $coerced = self::coercePrimitiveValue($decoded, $schema);
            $jsonSchema = OpenApiSchemaConverter::convert($schema, $version, SchemaContext::Request);

            $schemaObject = self::toObject($jsonSchema);
            $dataObject = self::toObject($coerced);

            $result = $this->opisValidator->validate($dataObject, $schemaObject);
            if ($result->isValid()) {
                continue;
            }

            $formatted = $this->errorFormatter->format($result->error());
            foreach ($formatted as $path => $messages) {
                $suffix = $path === '/' ? '' : $path;
                foreach ($messages as $message) {
                    $errors[] = "[path.{$name}{$suffix}] {$message}";
                }
            }
        }

        // Reverse check: every `{placeholder}` in the URL template MUST be declared
        // as an `in: path` parameter per OpenAPI. A captured-but-not-declared name
        // means the spec author forgot the declaration entirely, which would otherwise
        // let any value pass silently — the drift this library exists to catch.
        foreach ($pathVariables as $name => $_) {
            if (!isset($declared[$name])) {
                $errors[] = "[path.{$name}] placeholder in {$method} {$matchedPath} template is not declared as an 'in: path' parameter — malformed spec (OpenAPI requires every placeholder to be declared).";
            }
        }

        return $errors;
    }

    /**
     * Validate header parameters declared by the matched operation (or
     * inherited from the path-level `parameters` block).
     *
     * HTTP header names are case-insensitive (RFC 7230) so both the spec
     * `name` and the caller-supplied `$headers` keys are lower-cased before
     * matching. Error messages keep the spec's original casing so users can
     * grep the spec directly.
     *
     * Per OpenAPI 3.x, `Accept`, `Content-Type`, and `Authorization`
     * declarations are ignored — these are controlled by content negotiation
     * and security schemes, not arbitrary header parameters.
     *
     * Header values arriving as `array<string>` (Laravel's HeaderBag models
     * repeated occurrences this way) are unwrapped to a single value when the
     * array holds exactly one element. Multi-value arrays against scalar
     * schemas produce a hard error — real frameworks disagree on which of
     * the repeated values "wins" (Laravel picks first, Symfony picks last),
     * so silently picking one would mask a drift the contract test exists to
     * expose. Empty arrays are treated as missing. `style: simple` with
     * `type: array | object` is out of scope.
     *
     * @param list<array<string, mixed>> $parameters pre-collected merged parameters
     * @param array<string, mixed> $headers caller-supplied request headers
     *
     * @return string[]
     */
    private function validateHeaderParameters(
        string $method,
        string $matchedPath,
        array $parameters,
        array $headers,
        OpenApiVersion $version,
    ): array {
        $errors = [];
        $normalizedHeaders = self::normalizeHeaders($headers);

        foreach ($parameters as $param) {
            if (($param['in'] ?? null) !== 'header') {
                continue;
            }

            /** @var string $name */
            $name = $param['name'];
            $lowerName = strtolower($name);

            $required = ($param['required'] ?? false) === true;

            // Same reasoning as query/path: a required parameter without a schema would
            // silently pass every request, so surface it as a hard spec error. Optional
            // entries without a schema have nothing to validate — let them through.
            if (!isset($param['schema']) || !is_array($param['schema'])) {
                if ($required) {
                    $errors[] = "[header.{$name}] required parameter has no schema for {$method} {$matchedPath} — cannot validate.";
                }

                continue;
            }

            /** @var array<string, mixed> $schema */
            $schema = $param['schema'];

            $rawValue = $normalizedHeaders[$lowerName] ?? null;

            // `null` and `[]` (empty repeated-header array) both collapse to "missing".
            // A repeated header that was sent zero times is semantically absent.
            if ($rawValue === null || $rawValue === []) {
                if ($required) {
                    $errors[] = "[header.{$name}] required header is missing.";
                }

                continue;
            }

            if (is_array($rawValue)) {
                // HeaderBag shape: list<string>. Single-element arrays are the common
                // case (Laravel always wraps) — unwrap. Multi-element means the client
                // sent the header more than once; frameworks disagree on which value
                // is "canonical" (Laravel: first, Symfony: last), so silently picking
                // one would mask drift. Surface it so the spec author / client fixes
                // the duplicate.
                if (count($rawValue) > 1) {
                    $errors[] = sprintf(
                        '[header.%s] multiple values received (count=%d) but schema expects a single value; refusing to pick one silently.',
                        $name,
                        count($rawValue),
                    );

                    continue;
                }

                $rawValue = $rawValue[array_key_first($rawValue)];
            }

            // Mirror the pre-unwrap missing-header branch for the post-unwrap case:
            // `['X-Foo' => [null]]` is a caller bug shaped identically to an absent
            // header. Letting it flow to coercion would either silently pass against
            // a `nullable` schema or surface as a `/` type mismatch from opis — both
            // hide the root cause.
            if ($rawValue === null) {
                if ($required) {
                    $errors[] = "[header.{$name}] required header is missing.";
                }

                continue;
            }

            // Guard against caller-side bugs that smuggle a non-scalar (nested array,
            // object, resource) past the unwrap. Without this, opis would report a
            // JSON-Pointer type mismatch that hides the real cause — that the caller
            // never produced a header-shaped value in the first place.
            if (!is_scalar($rawValue)) {
                $errors[] = sprintf(
                    '[header.%s] value must be a scalar (string|int|bool|float); got %s.',
                    $name,
                    get_debug_type($rawValue),
                );

                continue;
            }

            $coerced = self::coercePrimitiveValue($rawValue, $schema);
            $jsonSchema = OpenApiSchemaConverter::convert($schema, $version, SchemaContext::Request);

            $schemaObject = self::toObject($jsonSchema);
            $dataObject = self::toObject($coerced);

            $result = $this->opisValidator->validate($dataObject, $schemaObject);
            if ($result->isValid()) {
                continue;
            }

            $formatted = $this->errorFormatter->format($result->error());
            foreach ($formatted as $path => $messages) {
                $suffix = $path === '/' ? '' : $path;
                foreach ($messages as $message) {
                    $errors[] = "[header.{$name}{$suffix}] {$message}";
                }
            }
        }

        return $errors;
    }

    /**
     * Validate the endpoint's `security` requirement against the incoming
     * request. Supports `http` + `bearer` and `apiKey` (in: header|query|cookie)
     * schemes. OAuth2 / OpenID Connect are out of scope for phase 1 and are
     * treated as unsupported: any requirement entry containing an unsupported
     * scheme is skipped entirely (contributes neither pass nor fail).
     *
     * Resolution: operation-level `security` takes precedence; otherwise the
     * root-level `security` is inherited. `security: []` (empty array) explicitly
     * opts out of all authentication, so it returns `[]` immediately regardless
     * of root-level definitions. Missing `security` on both levels also returns
     * `[]` (no authentication required).
     *
     * OR / AND semantics (per OpenAPI):
     * - Multiple entries in the `security` array → OR (any one satisfied is enough)
     * - Multiple schemes within a single entry object → AND (all must be satisfied)
     *
     * Malformed spec elements (undefined scheme references, scalar entries,
     * missing `type` / `scheme` / `name` / `in` fields) are always surfaced as
     * hard errors, even if another requirement entry is satisfied — a broken
     * security declaration is something the spec author must fix.
     *
     * @param array<string, mixed> $spec full spec root (for `components.securitySchemes` + root-level `security`)
     * @param array<string, mixed> $operation operation spec (for operation-level `security`)
     * @param array<array-key, mixed> $headers caller-supplied request headers
     * @param array<string, mixed> $queryParams parsed query string
     * @param array<string, mixed> $cookies request cookies (for `apiKey` with `in: cookie`)
     *
     * @return string[]
     */
    private function validateSecurity(
        string $method,
        string $matchedPath,
        array $spec,
        array $operation,
        array $headers,
        array $queryParams,
        array $cookies,
    ): array {
        $security = array_key_exists('security', $operation)
            ? $operation['security']
            : ($spec['security'] ?? null);

        if ($security === null) {
            return [];
        }

        if (!is_array($security)) {
            return [
                sprintf(
                    '[security] %s %s: operation/root-level `security` must be an array of requirement objects, got %s.',
                    $method,
                    $matchedPath,
                    get_debug_type($security),
                ),
            ];
        }

        if ($security === []) {
            return [];
        }

        $schemes = $spec['components']['securitySchemes'] ?? [];
        // A non-array `components.securitySchemes` is a malformed spec — without
        // this hard error, every scheme reference below would be reported as
        // "undefined scheme … add it under components.securitySchemes", which
        // misdirects the spec author away from the real cause.
        if (!is_array($schemes)) {
            return [
                sprintf(
                    '[security] %s %s: components.securitySchemes must be an object mapping scheme names to definitions, got %s.',
                    $method,
                    $matchedPath,
                    get_debug_type($schemes),
                ),
            ];
        }

        $normalizedHeaders = self::normalizeHeaders($headers);

        $hardErrors = [];
        $failureErrors = [];
        $satisfied = false;

        foreach ($security as $entryIndex => $entry) {
            if (!is_array($entry)) {
                $hardErrors[] = sprintf(
                    '[security] %s %s: security requirement at index %d must be an object mapping scheme names to scope arrays, got %s.',
                    $method,
                    $matchedPath,
                    is_int($entryIndex) ? $entryIndex : 0,
                    get_debug_type($entry),
                );

                continue;
            }

            $entryHasHardError = false;
            $entryHasUnsupported = false;
            /** @var array<string, array{kind: string, def: array<string, mixed>}> $validatable */
            $validatable = [];

            foreach ($entry as $schemeName => $_scopes) {
                if (!is_string($schemeName)) {
                    $hardErrors[] = sprintf(
                        '[security] %s %s: security scheme name must be a string, got %s.',
                        $method,
                        $matchedPath,
                        get_debug_type($schemeName),
                    );
                    $entryHasHardError = true;

                    continue;
                }

                $schemeDef = $schemes[$schemeName] ?? null;
                if (!is_array($schemeDef)) {
                    $hardErrors[] = sprintf(
                        "[security] %s %s: security requirement references undefined scheme '%s' — add it under components.securitySchemes.",
                        $method,
                        $matchedPath,
                        $schemeName,
                    );
                    $entryHasHardError = true;

                    continue;
                }

                $classification = $this->classifySecurityScheme($schemeDef);

                if ($classification['kind'] === 'malformed') {
                    $hardErrors[] = sprintf(
                        "[security] %s %s: security scheme '%s' is malformed: %s",
                        $method,
                        $matchedPath,
                        $schemeName,
                        $classification['reason'],
                    );
                    $entryHasHardError = true;

                    continue;
                }

                if ($classification['kind'] === 'unsupported') {
                    $entryHasUnsupported = true;

                    continue;
                }

                $validatable[$schemeName] = ['kind' => $classification['kind'], 'def' => $schemeDef];
            }

            if ($entryHasHardError) {
                continue;
            }

            if ($entryHasUnsupported) {
                continue;
            }

            $entryFailures = [];
            foreach ($validatable as $schemeName => $info) {
                $schemeErrors = $this->checkSchemeSatisfaction(
                    $info['kind'],
                    $info['def'],
                    $normalizedHeaders,
                    $queryParams,
                    $cookies,
                );
                foreach ($schemeErrors as $schemeError) {
                    $entryFailures[] = sprintf(
                        "[security] %s %s: requirement '%s' not satisfied: %s",
                        $method,
                        $matchedPath,
                        $schemeName,
                        $schemeError,
                    );
                }
            }

            if ($entryFailures === []) {
                $satisfied = true;

                break;
            }

            $failureErrors = [...$failureErrors, ...$entryFailures];
        }

        if ($satisfied) {
            return $hardErrors;
        }

        // If every requirement entry was either skipped (unsupported scheme
        // within) or malformed (already captured in $hardErrors), there is no
        // *validatable* entry that failed. Returning early avoids blocking a
        // test for a spec we fundamentally cannot evaluate (false-negative
        // avoidance for oauth2-only endpoints).
        if ($failureErrors === []) {
            return $hardErrors;
        }

        return [...$hardErrors, ...$failureErrors];
    }

    /**
     * Classify a security scheme definition into one of:
     * - `bearer`      — http + scheme=bearer (validatable)
     * - `apiKey`      — apiKey in header|query|cookie (validatable)
     * - `unsupported` — a spec-allowed type we intentionally defer (oauth2,
     *                   openIdConnect, mutualTLS, or http with a non-bearer
     *                   scheme). Phase 1 skip — false-negative avoidance.
     * - `malformed`   — missing/invalid required fields, or a `type` that is
     *                   not in the OpenAPI-enumerated set. A typo like
     *                   `{"type": "htpp"}` MUST surface as a hard error
     *                   rather than silently skipping — otherwise the
     *                   library would pass every request for that endpoint.
     *
     * @param array<string, mixed> $schemeDef
     *
     * @return array{kind: string, reason?: string}
     */
    private function classifySecurityScheme(array $schemeDef): array
    {
        $type = $schemeDef['type'] ?? null;
        if (!is_string($type) || $type === '') {
            return ['kind' => 'malformed', 'reason' => "missing required 'type' field."];
        }

        if ($type === 'apiKey') {
            $in = $schemeDef['in'] ?? null;
            $name = $schemeDef['name'] ?? null;
            if (!is_string($in) || !is_string($name)) {
                return ['kind' => 'malformed', 'reason' => "apiKey scheme requires string 'in' and 'name' fields."];
            }
            if (!in_array($in, ['header', 'query', 'cookie'], true)) {
                return ['kind' => 'malformed', 'reason' => "apiKey scheme 'in' must be one of header|query|cookie, got '{$in}'."];
            }

            return ['kind' => 'apiKey'];
        }

        if ($type === 'http') {
            $scheme = $schemeDef['scheme'] ?? null;
            if (!is_string($scheme)) {
                return ['kind' => 'malformed', 'reason' => "http scheme requires a string 'scheme' field (e.g. 'bearer', 'basic')."];
            }

            if (strtolower($scheme) === 'bearer') {
                return ['kind' => 'bearer'];
            }

            // http + basic / digest / etc. are well-formed but phase 1 cannot validate them.
            return ['kind' => 'unsupported'];
        }

        if ($type === 'oauth2' || $type === 'openIdConnect' || $type === 'mutualTLS') {
            return ['kind' => 'unsupported'];
        }

        return [
            'kind' => 'malformed',
            'reason' => "unknown type '{$type}' — OpenAPI 3.x enumerates apiKey|http|oauth2|openIdConnect|mutualTLS.",
        ];
    }

    /**
     * Check whether a single (already-classified, well-formed) security scheme
     * is satisfied by the request. Returns an empty list when satisfied, or
     * one or more error strings explaining why not.
     *
     * @param array<string, mixed> $schemeDef
     * @param array<string, mixed> $normalizedHeaders lower-cased header map
     * @param array<string, mixed> $queryParams
     * @param array<string, mixed> $cookies
     *
     * @return string[]
     */
    private function checkSchemeSatisfaction(
        string $kind,
        array $schemeDef,
        array $normalizedHeaders,
        array $queryParams,
        array $cookies,
    ): array {
        if ($kind === 'bearer') {
            $raw = $normalizedHeaders['authorization'] ?? null;
            $value = $this->extractSingleStringValue($raw);
            if ($value === null || $value === '') {
                return ['Authorization header is missing.'];
            }

            // RFC 6750 §2.1: `Bearer <token>`. Scheme name is case-insensitive
            // per RFC 7235 §2.1, so we accept "Bearer" / "bearer" / "BEARER" etc.
            // Require a non-empty token portion; "Bearer" alone or "Bearer " is
            // not a valid credential.
            if (preg_match('/^bearer\s+(\S+)/i', $value) !== 1) {
                return ["Authorization header does not contain a 'Bearer <token>' credential."];
            }

            return [];
        }

        if ($kind === 'apiKey') {
            /** @var string $in */
            $in = $schemeDef['in'];
            /** @var string $name */
            $name = $schemeDef['name'];

            $raw = match ($in) {
                'header' => $normalizedHeaders[strtolower($name)] ?? null,
                'query' => $queryParams[$name] ?? null,
                'cookie' => $cookies[$name] ?? null,
                default => null,
            };

            $value = $this->extractSingleStringValue($raw);
            if ($value === null || $value === '') {
                return [sprintf("api key '%s' is missing from the %s.", $name, $in)];
            }

            return [];
        }

        throw new LogicException("checkSchemeSatisfaction received unexpected kind '{$kind}'.");
    }

    /**
     * Return the first element of an array, the value itself if it's a string,
     * or `null` otherwise (absent, empty array, or non-string scalar like int
     * or bool). No coercion is performed — a non-string first element still
     * returns `null`.
     *
     * Unlike `validateHeaderParameters()` (which rejects multi-value arrays as
     * a hard error to force the spec author to pick a canonical value), the
     * security layer silently accepts the first element. Presence of a
     * credential is all the security layer checks, and duplicate headers are
     * a framework-level concern surfaced elsewhere.
     */
    private function extractSingleStringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            if ($value === []) {
                return null;
            }

            $first = $value[array_key_first($value)];

            return is_string($first) ? $first : null;
        }

        return is_string($value) ? $value : null;
    }

    /**
     * Merge path-level and operation-level parameters. Operation-level entries
     * override path-level ones with the same `name` + `in` (per OpenAPI spec).
     *
     * Malformed entries are surfaced as errors rather than silently skipped,
     * because for a contract-testing tool the absence of an error means
     * "validated and OK" — silently dropping a parameter would leave drift
     * invisible. `$ref` entries never reach this method: `OpenApiSpecLoader`
     * resolves internal refs and throws on external/circular/unresolvable ones
     * at load time.
     *
     * @param array<string, mixed> $pathSpec
     * @param array<string, mixed> $operation
     *
     * @return array{0: list<array<string, mixed>>, 1: string[]}
     */
    private function collectParameters(string $method, string $matchedPath, array $pathSpec, array $operation): array
    {
        $merged = [];
        $errors = [];

        foreach ([$pathSpec['parameters'] ?? [], $operation['parameters'] ?? []] as $source) {
            if (!is_array($source)) {
                continue;
            }

            foreach ($source as $param) {
                if (!is_array($param)) {
                    $errors[] = "Malformed parameter entry for {$method} {$matchedPath}: expected object, got scalar.";

                    continue;
                }

                if (!isset($param['in'], $param['name']) || !is_string($param['in']) || !is_string($param['name'])) {
                    $errors[] = "Malformed parameter entry for {$method} {$matchedPath}: 'name' and 'in' must be strings.";

                    continue;
                }

                // OAS 3.x §4.7.12.1 says `Accept`/`Content-Type`/`Authorization`
                // in:header parameter definitions SHALL be ignored — content
                // negotiation and security schemes own them. Silently skipping
                // would let a spec author's `required: true` pass every request;
                // surface as a hard spec error and drop the entry so downstream
                // per-request validation stays OAS-compliant (no runtime effect).
                // The `in === 'header'` guard keeps a non-header parameter that
                // coincidentally shares a reserved name (e.g. `in: query, name: accept`)
                // out of scope — only in:header entries are governed by §4.7.12.1.
                if ($param['in'] === 'header' && in_array(strtolower($param['name']), self::RESERVED_HEADER_NAMES, true)) {
                    $errors[] = sprintf(
                        'Reserved in:header parameter declared for %s %s: [header.%s] — per OpenAPI 3.x §4.7.12.1, `Accept`/`Content-Type`/`Authorization` parameter definitions SHALL be ignored. Remove the declaration or use `content` / security schemes instead.',
                        $method,
                        $matchedPath,
                        $param['name'],
                    );

                    continue;
                }

                $key = $param['in'] . ':' . $param['name'];
                $merged[$key] = $param;
            }
        }

        return [array_values($merged), $errors];
    }

    /**
     * Conservatively coerce a query string value to the type declared by the
     * schema. When the string is not a clean representation of the target
     * type, the original value is returned unchanged so opis can surface a
     * meaningful type error rather than silently passing.
     *
     * For multi-type schemas (OAS 3.1 `type: ["integer", "null"]`) the first
     * non-`null` primitive type is used as the coercion target.
     *
     * @param array<string, mixed> $schema
     */
    private function coerceQueryValue(mixed $value, array $schema): mixed
    {
        $type = $schema['type'] ?? null;

        if (is_array($type)) {
            $type = self::firstPrimitiveType($type);
        }

        if ($type === 'array') {
            $value = is_array($value) ? array_values($value) : [$value];

            $itemSchema = $schema['items'] ?? null;
            if (is_array($itemSchema)) {
                return array_map(fn(mixed $item): mixed => $this->coerceQueryValue($item, $itemSchema), $value);
            }

            return $value;
        }

        return self::coercePrimitiveFromType($value, $type);
    }

    /**
     * Validate the request body against the operation's `requestBody` schema.
     *
     * Returns an empty list when the body is acceptable (including when the
     * spec defines no body, no content, no JSON content type, or no schema).
     * Hard spec-level errors (malformed `requestBody` / `content`) are
     * reported as standard error entries so they compose with query errors.
     *
     * @param array<string, mixed> $operation
     *
     * @return string[]
     */
    private function validateRequestBody(
        string $specName,
        string $method,
        string $matchedPath,
        array $operation,
        mixed $requestBody,
        ?string $contentType,
        OpenApiVersion $version,
    ): array {
        // OpenAPI: a missing requestBody means the operation accepts no body — treat as success.
        if (!isset($operation['requestBody'])) {
            return [];
        }

        // A present-but-non-array requestBody signals a malformed spec (stray scalar).
        // Contract-testing tools should surface this, not mask it as "no body".
        if (!is_array($operation['requestBody'])) {
            return [
                "Malformed 'requestBody' for {$method} {$matchedPath} in '{$specName}' spec: expected object, got scalar.",
            ];
        }

        /** @var array<string, mixed> $requestBodySpec */
        $requestBodySpec = $operation['requestBody'];

        $required = ($requestBodySpec['required'] ?? false) === true;

        if (!isset($requestBodySpec['content'])) {
            return [];
        }

        if (!is_array($requestBodySpec['content'])) {
            return [
                "Malformed 'requestBody.content' for {$method} {$matchedPath} in '{$specName}' spec: expected object, got scalar.",
            ];
        }

        /** @var array<string, mixed> $content */
        $content = $requestBodySpec['content'];

        foreach ($content as $mediaType => $mediaTypeSpec) {
            // The @var on $content narrows values to array, but PHPDoc is unchecked at
            // runtime — a malformed spec like `content: {"application/json": "oops"}`
            // would TypeError on downstream array accesses. Surface it as a loud spec
            // error instead, matching the sibling guard on `requestBody.content` above.
            if (!is_array($mediaTypeSpec)) {
                return [
                    "Malformed 'requestBody.content[\"{$mediaType}\"]' for {$method} {$matchedPath} in '{$specName}' spec: expected object, got scalar.",
                ];
            }

            // `schema: "oops"` (or any other non-array scalar) would slip past the
            // downstream `isset(...['schema'])` presence check and reach
            // OpenApiSchemaConverter::convert() as a scalar, producing a confusing
            // TypeError instead of a spec-level error. array_key_exists rather than
            // isset so an explicit `schema: null` is also flagged.
            if (array_key_exists('schema', $mediaTypeSpec) && !is_array($mediaTypeSpec['schema'])) {
                return [
                    "Malformed 'requestBody.content[\"{$mediaType}\"].schema' for {$method} {$matchedPath} in '{$specName}' spec: expected object, got scalar.",
                ];
            }
        }

        // When the actual request Content-Type is provided, handle content negotiation:
        // non-JSON types are checked for spec presence only, while JSON-compatible types
        // fall through to schema validation against the first JSON media type in the spec.
        if ($contentType !== null) {
            $normalizedType = $this->normalizeMediaType($contentType);

            if (!$this->isJsonContentType($normalizedType)) {
                if ($this->isContentTypeInSpec($normalizedType, $content)) {
                    return [];
                }

                $defined = implode(', ', array_keys($content));

                return [
                    "Request Content-Type '{$normalizedType}' is not defined for {$method} {$matchedPath} in '{$specName}' spec. Defined content types: {$defined}",
                ];
            }

            // JSON-compatible request: fall through to existing JSON schema validation.
            // JSON types are treated as interchangeable (e.g. application/vnd.api+json
            // validates against an application/json spec entry) because the schema is
            // the same regardless of the specific JSON media type.
        }

        $jsonContentType = $this->findJsonContentType($content);

        // If no JSON-compatible content type is defined, skip body validation.
        // This validator only handles JSON schemas; non-JSON types (e.g. application/xml,
        // application/octet-stream) are outside its scope.
        if ($jsonContentType === null) {
            return [];
        }

        if (!isset($content[$jsonContentType]['schema'])) {
            return [];
        }

        if ($requestBody === null) {
            if (!$required) {
                return [];
            }

            return [
                "Request body is empty but {$method} {$matchedPath} defines a required JSON request body schema in '{$specName}' spec.",
            ];
        }

        /** @var array<string, mixed> $schema */
        $schema = $content[$jsonContentType]['schema'];
        $jsonSchema = OpenApiSchemaConverter::convert($schema, $version, SchemaContext::Request);

        $schemaObject = self::toObject($jsonSchema);
        $dataObject = self::toObject($requestBody);

        $result = $this->opisValidator->validate($dataObject, $schemaObject);

        if ($result->isValid()) {
            return [];
        }

        $formattedErrors = $this->errorFormatter->format($result->error());

        $errors = [];
        foreach ($formattedErrors as $path => $messages) {
            foreach ($messages as $message) {
                $errors[] = "[{$path}] {$message}";
            }
        }

        return $errors;
    }

    /**
     * Find the first JSON-compatible content type from the request body spec.
     *
     * Matches "application/json" exactly and any type with a "+json" structured
     * syntax suffix (RFC 6838), such as "application/problem+json" and
     * "application/vnd.api+json". Matching is case-insensitive.
     *
     * @param array<string, mixed> $content
     */
    private function findJsonContentType(array $content): ?string
    {
        foreach ($content as $contentType => $mediaType) {
            $lower = strtolower($contentType);

            if ($this->isJsonContentType($lower)) {
                return $contentType;
            }
        }

        return null;
    }

    /**
     * Extract the media type portion before any parameters (e.g. charset),
     * and return it lower-cased.
     *
     * Example: "text/html; charset=utf-8" → "text/html"
     */
    private function normalizeMediaType(string $contentType): string
    {
        $mediaType = strstr($contentType, ';', true);

        return strtolower(trim($mediaType !== false ? $mediaType : $contentType));
    }

    /**
     * Check whether the given (already normalised, lower-cased) request content
     * type matches any content type key defined in the spec. Spec keys are
     * lower-cased before comparison.
     *
     * @param array<string, mixed> $content
     */
    private function isContentTypeInSpec(string $requestContentType, array $content): bool
    {
        foreach ($content as $specContentType => $mediaType) {
            if (strtolower($specContentType) === $requestContentType) {
                return true;
            }
        }

        return false;
    }

    /**
     * True for "application/json" or any "+json" structured syntax suffix (RFC 6838).
     * Expects a lower-cased media type without parameters.
     */
    private function isJsonContentType(string $lowerContentType): bool
    {
        return $lowerContentType === 'application/json' || str_ends_with($lowerContentType, '+json');
    }
}
