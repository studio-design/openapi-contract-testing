<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use const JSON_THROW_ON_ERROR;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

use function array_keys;
use function implode;
use function json_decode;
use function json_encode;
use function str_ends_with;
use function strtolower;

final class OpenApiResponseValidator
{
    public function validate(
        string $specName,
        string $method,
        string $requestPath,
        int $statusCode,
        mixed $responseBody,
    ): OpenApiValidationResult {
        $spec = OpenApiSpecLoader::load($specName);

        $version = OpenApiVersion::fromSpec($spec);

        /** @var string[] $specPaths */
        $specPaths = array_keys($spec['paths'] ?? []);
        $matcher = new OpenApiPathMatcher($specPaths, OpenApiSpecLoader::getStripPrefixes());
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
            ]);
        }

        $statusCodeStr = (string) $statusCode;
        $responses = $pathSpec[$lowerMethod]['responses'] ?? [];

        if (!isset($responses[$statusCodeStr])) {
            return OpenApiValidationResult::failure([
                "Status code {$statusCode} not defined for {$method} {$matchedPath} in '{$specName}' spec.",
            ]);
        }

        $responseSpec = $responses[$statusCodeStr];

        // If no content is defined for this response, skip body validation (e.g. 204 No Content)
        if (!isset($responseSpec['content'])) {
            return OpenApiValidationResult::success($matchedPath);
        }

        /** @var array<string, array<string, mixed>> $content */
        $content = $responseSpec['content'];
        $jsonContentType = $this->findJsonContentType($content);

        if ($jsonContentType === null) {
            $definedTypes = array_keys($content);

            return OpenApiValidationResult::failure([
                "No JSON-compatible content type found for {$method} {$matchedPath} (status {$statusCode}) in '{$specName}' spec. Defined content types: " . implode(', ', $definedTypes),
            ]);
        }

        if (!isset($content[$jsonContentType]['schema'])) {
            return OpenApiValidationResult::success($matchedPath);
        }

        if ($responseBody === null) {
            return OpenApiValidationResult::failure([
                "Response body is empty but {$method} {$matchedPath} (status {$statusCode}) defines a JSON-compatible response schema in '{$specName}' spec.",
            ]);
        }

        /** @var array<string, mixed> $schema */
        $schema = $content[$jsonContentType]['schema'];
        $jsonSchema = OpenApiSchemaConverter::convert($schema, $version);

        // opis/json-schema requires an object, so encode then decode
        $schemaObject = json_decode(
            (string) json_encode($jsonSchema, JSON_THROW_ON_ERROR),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );

        $dataObject = json_decode(
            (string) json_encode($responseBody, JSON_THROW_ON_ERROR),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );

        $validator = new Validator();
        $result = $validator->validate($dataObject, $schemaObject);

        if ($result->isValid()) {
            return OpenApiValidationResult::success($matchedPath);
        }

        $formatter = new ErrorFormatter();
        $formattedErrors = $formatter->format($result->error());

        $errors = [];
        foreach ($formattedErrors as $path => $messages) {
            foreach ($messages as $message) {
                $errors[] = "[{$path}] {$message}";
            }
        }

        return OpenApiValidationResult::failure($errors);
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

            if ($lower === 'application/json' || str_ends_with($lower, '+json')) {
                return $contentType;
            }
        }

        return null;
    }
}
