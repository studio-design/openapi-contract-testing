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
     * Composes two validation phases — query parameters and request body — and
     * returns a single result. Errors from both phases are accumulated so a
     * single test run surfaces every contract drift the request exhibits.
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
        $matchedPath = $matcher->match($requestPath);

        if ($matchedPath === null) {
            return OpenApiValidationResult::failure([
                "No matching path found in '{$specName}' spec for: {$requestPath}",
            ]);
        }

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

        $queryErrors = $this->validateQueryParameters(
            $method,
            $matchedPath,
            $pathSpec,
            $operation,
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

        $errors = [...$queryErrors, ...$bodyErrors];

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
     * Coerce a query string to int, guarding against overflow. `filter_var`
     * returns `false` for values exceeding PHP_INT_MAX/MIN or containing
     * non-digit characters, so the original string falls through and opis
     * reports a type mismatch instead of receiving a silently truncated value.
     */
    private static function coerceToInt(string $value): int|string
    {
        $result = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($result) ? $result : $value;
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
     * @param array<string, mixed> $pathSpec
     * @param array<string, mixed> $operation
     * @param array<string, mixed> $queryParams
     *
     * @return string[]
     */
    private function validateQueryParameters(
        string $method,
        string $matchedPath,
        array $pathSpec,
        array $operation,
        array $queryParams,
        OpenApiVersion $version,
    ): array {
        [$parameters, $errors] = $this->collectParameters($method, $matchedPath, $pathSpec, $operation);

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
