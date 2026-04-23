<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

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
use function implode;
use function is_array;
use function is_numeric;
use function is_string;
use function preg_match;
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
     * @param array<string, mixed> $headers reserved for future use; ignored in this phase
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

        $queryErrors = $this->validateQueryParameters($pathSpec, $operation, $queryParams, $version);
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
        array $pathSpec,
        array $operation,
        array $queryParams,
        OpenApiVersion $version,
    ): array {
        $parameters = $this->collectParameters($pathSpec, $operation);

        $errors = [];
        foreach ($parameters as $param) {
            if (($param['in'] ?? null) !== 'query') {
                continue;
            }

            /** @var string $name */
            $name = $param['name'];

            if (!isset($param['schema']) || !is_array($param['schema'])) {
                // No schema → nothing to validate against; mirrors body behaviour.
                continue;
            }

            /** @var array<string, mixed> $schema */
            $schema = $param['schema'];
            $required = ($param['required'] ?? false) === true;

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
                foreach ($messages as $message) {
                    $errors[] = "[query.{$name}{$path}] {$message}";
                }
            }
        }

        return $errors;
    }

    /**
     * Merge path-level and operation-level parameters. Operation-level entries
     * override path-level ones with the same `name` + `in` (per OpenAPI spec).
     * Malformed entries (missing or non-string `name`/`in`) are silently
     * skipped — they cannot be uniquely identified for override matching.
     *
     * @param array<string, mixed> $pathSpec
     * @param array<string, mixed> $operation
     *
     * @return list<array<string, mixed>>
     */
    private function collectParameters(array $pathSpec, array $operation): array
    {
        $merged = [];

        foreach ([$pathSpec['parameters'] ?? [], $operation['parameters'] ?? []] as $source) {
            if (!is_array($source)) {
                continue;
            }

            foreach ($source as $param) {
                if (!is_array($param)) {
                    continue;
                }

                if (!isset($param['in'], $param['name']) || !is_string($param['in']) || !is_string($param['name'])) {
                    continue;
                }

                $key = $param['in'] . ':' . $param['name'];
                $merged[$key] = $param;
            }
        }

        return array_values($merged);
    }

    /**
     * Conservatively coerce a query string value to the type declared by the
     * schema. When the string is not a clean representation of the target
     * type, the original value is returned unchanged so opis can surface a
     * meaningful type error rather than silently passing.
     *
     * @param array<string, mixed> $schema
     */
    private function coerceQueryValue(mixed $value, array $schema): mixed
    {
        $type = $schema['type'] ?? null;

        if ($type === 'array') {
            if (!is_array($value)) {
                $value = [$value];
            }

            $itemSchema = $schema['items'] ?? null;
            if (is_array($itemSchema)) {
                /** @var list<mixed> $value */
                return array_map(fn(mixed $item): mixed => $this->coerceQueryValue($item, $itemSchema), $value);
            }

            return $value;
        }

        if (!is_string($value) || !is_string($type)) {
            return $value;
        }

        return match ($type) {
            'integer' => preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $value,
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
        $required = ($requestBodySpec['required'] ?? false) === true;

        if (!isset($requestBodySpec['content'])) {
            return [];
        }

        if (!is_array($requestBodySpec['content'])) {
            return [
                "Malformed 'requestBody.content' for {$method} {$matchedPath} in '{$specName}' spec: expected object, got scalar. Likely an unresolved \$ref or broken spec.",
            ];
        }

        /** @var array<string, array<string, mixed>> $content */
        $content = $requestBodySpec['content'];

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
     * @param array<string, array<string, mixed>> $content
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
     * @param array<string, array<string, mixed>> $content
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
