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

final class OpenApiResponseValidator
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

    public function validate(
        string $specName,
        string $method,
        string $requestPath,
        int $statusCode,
        mixed $responseBody,
        ?string $responseContentType = null,
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

        $statusCodeStr = (string) $statusCode;
        $responses = $pathSpec[$lowerMethod]['responses'] ?? [];

        if (!isset($responses[$statusCodeStr])) {
            return OpenApiValidationResult::failure([
                "Status code {$statusCode} not defined for {$method} {$matchedPath} in '{$specName}' spec.",
            ], $matchedPath);
        }

        $responseSpec = $responses[$statusCodeStr];

        // If no content is defined for this response, skip body validation (e.g. 204 No Content)
        if (!isset($responseSpec['content'])) {
            return OpenApiValidationResult::success($matchedPath);
        }

        /** @var array<string, array<string, mixed>> $content */
        $content = $responseSpec['content'];

        // When the actual response Content-Type is provided, handle content negotiation:
        // non-JSON types are checked for spec presence only, while JSON-compatible types
        // fall through to schema validation against the first JSON media type in the spec.
        if ($responseContentType !== null) {
            $normalizedType = $this->normalizeMediaType($responseContentType);

            if (!$this->isJsonContentType($normalizedType)) {
                // Non-JSON response: check if the content type is defined in the spec.
                if ($this->isContentTypeInSpec($normalizedType, $content)) {
                    return OpenApiValidationResult::success($matchedPath);
                }

                $defined = implode(', ', array_keys($content));

                return OpenApiValidationResult::failure([
                    "Response Content-Type '{$normalizedType}' is not defined for {$method} {$matchedPath} (status {$statusCode}) in '{$specName}' spec. Defined content types: {$defined}",
                ], $matchedPath);
            }

            // JSON-compatible response: fall through to existing JSON schema validation.
            // JSON types are treated as interchangeable (e.g. application/vnd.api+json
            // validates against an application/json spec entry) because the schema is
            // the same regardless of the specific JSON media type.
        }

        $jsonContentType = $this->findJsonContentType($content);

        // If no JSON-compatible content type is defined, skip body validation.
        // This validator only handles JSON schemas; non-JSON types (e.g. text/html,
        // application/xml) are outside its scope.
        if ($jsonContentType === null) {
            return OpenApiValidationResult::success($matchedPath);
        }

        if (!isset($content[$jsonContentType]['schema'])) {
            return OpenApiValidationResult::success($matchedPath);
        }

        if ($responseBody === null) {
            return OpenApiValidationResult::failure([
                "Response body is empty but {$method} {$matchedPath} (status {$statusCode}) defines a JSON-compatible response schema in '{$specName}' spec.",
            ], $matchedPath);
        }

        /** @var array<string, mixed> $schema */
        $schema = $content[$jsonContentType]['schema'];
        $jsonSchema = OpenApiSchemaConverter::convert($schema, $version);

        $schemaObject = self::toObject($jsonSchema);
        $dataObject = self::toObject($responseBody);

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
     * Find the first JSON-compatible content type from the response spec.
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
     * Check whether the given (already normalised, lower-cased) response content
     * type matches any content type key defined in the spec. Spec keys are
     * lower-cased before comparison.
     *
     * @param array<string, array<string, mixed>> $content
     */
    private function isContentTypeInSpec(string $responseContentType, array $content): bool
    {
        foreach ($content as $specContentType => $mediaType) {
            if (strtolower($specContentType) === $responseContentType) {
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
