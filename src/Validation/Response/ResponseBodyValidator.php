<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Response;

use Studio\OpenApiContractTesting\OpenApiResponseValidator;
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
     * entry. Returns a {@see ResponseBodyValidationResult} with an empty
     * `errors` list when the body is acceptable (non-JSON content types that
     * are present in the spec, JSON media types with no `schema` key, and
     * JSON bodies that pass schema validation). Hard failures (content-type
     * not defined, empty body against a JSON schema, schema mismatch) are
     * returned as error strings so the orchestrator can assemble the final
     * {@see OpenApiValidationResult}.
     *
     * `matchedContentType` is the spec key (with its original casing) the
     * body was checked against, or `null` when no spec lookup occurred —
     * used by coverage tracking to record per-(status, media-type) granularity.
     *
     * The 204-style "no content" case is handled upstream in
     * {@see OpenApiResponseValidator::validate()} — when the response spec
     * has no `content` key, this validator is never invoked.
     *
     * @param array<string, array<string, mixed>> $content the `responses[$status].content` map
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
    ): ResponseBodyValidationResult {
        // When the actual response Content-Type is provided, handle content negotiation:
        // non-JSON types are checked for spec presence only, while JSON-compatible types
        // fall through to schema validation against the first JSON media type in the spec.
        if ($responseContentType !== null) {
            $normalizedType = ContentTypeMatcher::normalizeMediaType($responseContentType);

            if (!ContentTypeMatcher::isJsonContentType($normalizedType)) {
                // Non-JSON response: check if the content type is defined in the spec.
                $matchedKey = ContentTypeMatcher::findContentTypeKey($normalizedType, $content);
                if ($matchedKey !== null) {
                    return new ResponseBodyValidationResult([], $matchedKey);
                }

                $defined = implode(', ', array_keys($content));

                return new ResponseBodyValidationResult(
                    [
                        "Response Content-Type '{$normalizedType}' is not defined for {$method} {$matchedPath} (status {$statusCode}) in '{$specName}' spec. Defined content types: {$defined}",
                    ],
                    null,
                );
            }

            // JSON-compatible response: continue to JSON schema validation below.
            // JSON types are treated as interchangeable (e.g. application/vnd.api+json
            // validates against an application/json spec entry) because the schema is
            // the same regardless of the specific JSON media type.
        }

        $jsonContentType = ContentTypeMatcher::findJsonContentType($content);

        // If no JSON-compatible content type is defined, skip body validation.
        // This validator only handles JSON schemas; non-JSON types (e.g. text/html,
        // application/xml) are outside its scope.
        if ($jsonContentType === null) {
            return new ResponseBodyValidationResult([], null);
        }

        if (!isset($content[$jsonContentType]['schema'])) {
            return new ResponseBodyValidationResult([], $jsonContentType);
        }

        if ($responseBody === null) {
            return new ResponseBodyValidationResult(
                [
                    "Response body is empty but {$method} {$matchedPath} (status {$statusCode}) defines a JSON-compatible response schema in '{$specName}' spec.",
                ],
                $jsonContentType,
            );
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

        return new ResponseBodyValidationResult($errors, $jsonContentType);
    }
}
