<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use const FILTER_VALIDATE_INT;
use const PHP_INT_MAX;

use InvalidArgumentException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use stdClass;

use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;
use function filter_var;
use function implode;
use function is_array;
use function is_int;
use function is_numeric;
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
     * Composes path-parameter, query-parameter, and request-body validation
     * plus any spec-level errors surfaced while collecting merged parameters,
     * and returns a single result. Errors from all sources are accumulated
     * so a single test run surfaces every contract drift the request exhibits.
     *
     * @param array<string, mixed> $queryParams parsed query string (string|array<string> per key)
     * @param array<string, mixed> $headers currently ignored; placeholder for header validation
     */
    public function validate(
        string $specName,
        string $method,
        string $requestPath,
        array $queryParams,
        array $headers,
        mixed $requestBody,
        ?string $contentType = null,
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
        // share a single view of the spec and spec-level errors (malformed entries,
        // unresolved $refs) are surfaced only once.
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
        $bodyErrors = $this->validateRequestBody(
            $specName,
            $method,
            $matchedPath,
            $operation,
            $requestBody,
            $contentType,
            $version,
        );

        $errors = [...$specErrors, ...$pathErrors, ...$queryErrors, ...$bodyErrors];

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
            $jsonSchema = OpenApiSchemaConverter::convert($schema, $version);

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
            $jsonSchema = OpenApiSchemaConverter::convert($schema, $version);

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
     * Merge path-level and operation-level parameters. Operation-level entries
     * override path-level ones with the same `name` + `in` (per OpenAPI spec).
     *
     * Malformed entries are surfaced as errors rather than silently skipped,
     * because for a contract-testing tool the absence of an error means
     * "validated and OK" — silently dropping a parameter would leave drift
     * invisible. `$ref` entries are flagged separately so users know the spec
     * needs to be pre-bundled (we don't resolve refs).
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

                if (array_key_exists('$ref', $param)) {
                    $ref = is_string($param['$ref']) ? $param['$ref'] : '(non-string $ref)';
                    $errors[] = "Parameter \$ref encountered for {$method} {$matchedPath} ('{$ref}') — \$ref resolution is not supported. Pre-bundle the spec (e.g. redocly bundle --dereference).";

                    continue;
                }

                if (!isset($param['in'], $param['name']) || !is_string($param['in']) || !is_string($param['name'])) {
                    $errors[] = "Malformed parameter entry for {$method} {$matchedPath}: 'name' and 'in' must be strings.";

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

        // A present-but-non-array requestBody signals a malformed spec (e.g. unresolved $ref,
        // stray scalar). Contract-testing tools should surface this, not mask it as "no body".
        if (!is_array($operation['requestBody'])) {
            return [
                "Malformed 'requestBody' for {$method} {$matchedPath} in '{$specName}' spec: expected object, got scalar. Likely an unresolved \$ref or broken spec.",
            ];
        }

        /** @var array<string, mixed> $requestBodySpec */
        $requestBodySpec = $operation['requestBody'];

        // Unresolved $ref at requestBody level: PHP parses it as an assoc array, so the
        // existing is_array guard lets it through. Without this check, the subsequent
        // `isset($requestBodySpec['content'])` returns false and the method silently
        // returns success — the worst possible outcome for a contract-testing tool.
        if (array_key_exists('$ref', $requestBodySpec)) {
            $ref = is_string($requestBodySpec['$ref']) ? $requestBodySpec['$ref'] : '(non-string $ref)';

            return [
                "RequestBody \$ref encountered for {$method} {$matchedPath} ('{$ref}') — \$ref resolution is not supported. Pre-bundle the spec (e.g. redocly bundle --dereference).",
            ];
        }

        $required = ($requestBodySpec['required'] ?? false) === true;

        if (!isset($requestBodySpec['content'])) {
            return [];
        }

        if (!is_array($requestBodySpec['content'])) {
            return [
                "Malformed 'requestBody.content' for {$method} {$matchedPath} in '{$specName}' spec: expected object, got scalar. Likely an unresolved \$ref or broken spec.",
            ];
        }

        /** @var array<string, mixed> $content */
        $content = $requestBodySpec['content'];

        // Unresolved $ref at content[mediaType] or content[mediaType].schema level.
        // Without these checks, (a) mediaType-level $ref silently returns success because
        // the `schema` key is absent, and (b) schema-level $ref reaches opis and throws
        // UnresolvedReferenceException with an unhelpful message. Flagging all entries
        // (not just the JSON-compatible one) catches broken specs regardless of which
        // Content-Type the caller uses.
        foreach ($content as $mediaType => $mediaTypeSpec) {
            // The @var on $content narrows values to array, but PHPDoc is unchecked at
            // runtime — a malformed spec like `content: {"application/json": "oops"}`
            // would TypeError on array_key_exists below. Surface it as a loud spec error
            // instead, matching the sibling guard on `requestBody.content` above.
            if (!is_array($mediaTypeSpec)) {
                return [
                    "Malformed 'requestBody.content[\"{$mediaType}\"]' for {$method} {$matchedPath} in '{$specName}' spec: expected object, got scalar.",
                ];
            }

            if (array_key_exists('$ref', $mediaTypeSpec)) {
                $ref = is_string($mediaTypeSpec['$ref']) ? $mediaTypeSpec['$ref'] : '(non-string $ref)';

                return [
                    "RequestBody content['{$mediaType}'] \$ref encountered for {$method} {$matchedPath} ('{$ref}') — \$ref resolution is not supported. Pre-bundle the spec (e.g. redocly bundle --dereference).",
                ];
            }

            if (isset($mediaTypeSpec['schema']) &&
                is_array($mediaTypeSpec['schema']) &&
                array_key_exists('$ref', $mediaTypeSpec['schema'])
            ) {
                $ref = is_string($mediaTypeSpec['schema']['$ref']) ? $mediaTypeSpec['schema']['$ref'] : '(non-string $ref)';

                return [
                    "RequestBody content['{$mediaType}'].schema \$ref encountered for {$method} {$matchedPath} ('{$ref}') — \$ref resolution is not supported. Pre-bundle the spec (e.g. redocly bundle --dereference).",
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
        $jsonSchema = OpenApiSchemaConverter::convert($schema, $version);

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
