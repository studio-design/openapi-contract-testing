<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Response;

use Studio\OpenApiContractTesting\OpenApiSchemaConverter;
use Studio\OpenApiContractTesting\OpenApiValidationResult;
use Studio\OpenApiContractTesting\OpenApiVersion;
use Studio\OpenApiContractTesting\SchemaContext;
use Studio\OpenApiContractTesting\Validation\Support\ContentTypeMatcher;
use Studio\OpenApiContractTesting\Validation\Support\ObjectConverter;
use Studio\OpenApiContractTesting\Validation\Support\SchemaValidatorRunner;

use function array_keys;
use function implode;

final class ResponseBodyValidator
{
    public function __construct(
        private readonly SchemaValidatorRunner $runner,
    ) {}

    /**
     * Validate the response body against the matched operation's response
     * entry. Returns an empty list when the body is acceptable (including
     * no-content responses, non-JSON content types without explicit schema,
     * and schema-less entries). Hard failures (content-type not defined,
     * empty body against JSON schema, schema mismatch) are returned as
     * error strings so the orchestrator can assemble the final
     * {@see OpenApiValidationResult}.
     *
     * @param array<string, array<string, mixed>> $content the `responses[$status].content` map
     *
     * @return string[]
     */
    public function validate(
        string $specName,
        string $method,
        string $matchedPath,
        int $statusCode,
        array $content,
        mixed $responseBody,
        ?string $responseContentType,
        OpenApiVersion $version,
    ): array {
        // When the actual response Content-Type is provided, handle content negotiation:
        // non-JSON types are checked for spec presence only, while JSON-compatible types
        // fall through to schema validation against the first JSON media type in the spec.
        if ($responseContentType !== null) {
            $normalizedType = ContentTypeMatcher::normalizeMediaType($responseContentType);

            if (!ContentTypeMatcher::isJsonContentType($normalizedType)) {
                // Non-JSON response: check if the content type is defined in the spec.
                if (ContentTypeMatcher::isContentTypeInSpec($normalizedType, $content)) {
                    return [];
                }

                $defined = implode(', ', array_keys($content));

                return [
                    "Response Content-Type '{$normalizedType}' is not defined for {$method} {$matchedPath} (status {$statusCode}) in '{$specName}' spec. Defined content types: {$defined}",
                ];
            }

            // JSON-compatible response: fall through to existing JSON schema validation.
            // JSON types are treated as interchangeable (e.g. application/vnd.api+json
            // validates against an application/json spec entry) because the schema is
            // the same regardless of the specific JSON media type.
        }

        $jsonContentType = ContentTypeMatcher::findJsonContentType($content);

        // If no JSON-compatible content type is defined, skip body validation.
        // This validator only handles JSON schemas; non-JSON types (e.g. text/html,
        // application/xml) are outside its scope.
        if ($jsonContentType === null) {
            return [];
        }

        if (!isset($content[$jsonContentType]['schema'])) {
            return [];
        }

        if ($responseBody === null) {
            return [
                "Response body is empty but {$method} {$matchedPath} (status {$statusCode}) defines a JSON-compatible response schema in '{$specName}' spec.",
            ];
        }

        /** @var array<string, mixed> $schema */
        $schema = $content[$jsonContentType]['schema'];
        $jsonSchema = OpenApiSchemaConverter::convert($schema, $version, SchemaContext::Response);

        $schemaObject = ObjectConverter::convert($jsonSchema);
        $dataObject = ObjectConverter::convert($responseBody);

        $formatted = $this->runner->validate($schemaObject, $dataObject);

        $errors = [];
        foreach ($formatted as $path => $messages) {
            foreach ($messages as $message) {
                $errors[] = "[{$path}] {$message}";
            }
        }

        return $errors;
    }
}
