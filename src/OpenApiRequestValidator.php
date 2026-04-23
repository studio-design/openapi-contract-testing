<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use const PHP_INT_MAX;

use InvalidArgumentException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use stdClass;

use function array_is_list;
use function array_keys;
use function implode;
use function is_array;
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
     * Validate a request body against the OpenAPI spec's requestBody schema.
     *
     * @param array<string, mixed> $queryParams reserved for future use; ignored in this skeleton
     * @param array<string, mixed> $headers reserved for future use; ignored in this skeleton
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
        $pathSpec = $spec['paths'][$matchedPath] ?? [];

        if (!isset($pathSpec[$lowerMethod])) {
            return OpenApiValidationResult::failure([
                "Method {$method} not defined for path {$matchedPath} in '{$specName}' spec.",
            ], $matchedPath);
        }

        $operation = $pathSpec[$lowerMethod];

        // Operations without a requestBody entry do not require a body (per AC of issue #42).
        if (!isset($operation['requestBody']) || !is_array($operation['requestBody'])) {
            return OpenApiValidationResult::success($matchedPath);
        }

        /** @var array<string, mixed> $requestBodySpec */
        $requestBodySpec = $operation['requestBody'];
        $required = ($requestBodySpec['required'] ?? false) === true;

        if (!isset($requestBodySpec['content']) || !is_array($requestBodySpec['content'])) {
            return OpenApiValidationResult::success($matchedPath);
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
                    return OpenApiValidationResult::success($matchedPath);
                }

                $defined = implode(', ', array_keys($content));

                return OpenApiValidationResult::failure([
                    "Request Content-Type '{$normalizedType}' is not defined for {$method} {$matchedPath} in '{$specName}' spec. Defined content types: {$defined}",
                ], $matchedPath);
            }
        }

        $jsonContentType = $this->findJsonContentType($content);

        // If no JSON-compatible content type is defined, skip body validation.
        // This validator only handles JSON schemas; non-JSON types (e.g. text/plain,
        // application/xml) are outside its scope.
        if ($jsonContentType === null) {
            return OpenApiValidationResult::success($matchedPath);
        }

        if (!isset($content[$jsonContentType]['schema'])) {
            return OpenApiValidationResult::success($matchedPath);
        }

        if ($requestBody === null) {
            if (!$required) {
                return OpenApiValidationResult::success($matchedPath);
            }

            return OpenApiValidationResult::failure([
                "Request body is empty but {$method} {$matchedPath} defines a required JSON request body schema in '{$specName}' spec.",
            ], $matchedPath);
        }

        /** @var array<string, mixed> $schema */
        $schema = $content[$jsonContentType]['schema'];
        $jsonSchema = OpenApiSchemaConverter::convert($schema, $version);

        $schemaObject = self::toObject($jsonSchema);
        $dataObject = self::toObject($requestBody);

        $result = $this->opisValidator->validate($dataObject, $schemaObject);

        if ($result->isValid()) {
            return OpenApiValidationResult::success($matchedPath);
        }

        $formattedErrors = $this->errorFormatter->format($result->error());

        $errors = [];
        foreach ($formattedErrors as $path => $messages) {
            foreach ($messages as $message) {
                $errors[] = "[{$path}] {$message}";
            }
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
