<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Laravel;

use Illuminate\Testing\TestResponse;
use JsonException;
use Studio\OpenApiContractTesting\HttpMethod;
use Studio\OpenApiContractTesting\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\OpenApiResponseValidator;
use Studio\OpenApiContractTesting\OpenApiSpecResolver;
use Symfony\Component\HttpFoundation\Response;
use WeakMap;

use function is_numeric;
use function is_string;
use function str_contains;
use function strtolower;

trait ValidatesOpenApiSchema
{
    use OpenApiSpecResolver;
    private static ?OpenApiResponseValidator $cachedValidator = null;
    private static ?int $cachedMaxErrors = null;

    /** @var null|WeakMap<TestResponse, true> */
    private static ?WeakMap $validatedResponses = null;

    public static function resetValidatorCache(): void
    {
        self::$cachedValidator = null;
        self::$cachedMaxErrors = null;
        self::$validatedResponses = null;
    }

    /**
     * Overrides Illuminate\Foundation\Testing\TestCase::createTestResponse so
     * every HTTP test call runs schema validation when auto_assert is enabled.
     * When the library is used outside Laravel, this method is never called.
     *
     * @param Response $response
     */
    protected function createTestResponse($response, $request = null): TestResponse
    {
        $testResponse = parent::createTestResponse($response, $request);
        $this->maybeAutoAssertOpenApiSchema($testResponse);

        return $testResponse;
    }

    protected function maybeAutoAssertOpenApiSchema(
        TestResponse $response,
        ?HttpMethod $method = null,
        ?string $path = null,
    ): void {
        if (config('openapi-contract-testing.auto_assert') !== true) {
            return;
        }

        if (self::isAlreadyValidated($response)) {
            return;
        }

        $this->assertResponseMatchesOpenApiSchema($response, $method, $path);
    }

    protected function openApiSpec(): string
    {
        $spec = config('openapi-contract-testing.default_spec');

        if (!is_string($spec) || $spec === '') {
            return '';
        }

        return $spec;
    }

    protected function openApiSpecFallback(): string
    {
        return $this->openApiSpec();
    }

    protected function assertResponseMatchesOpenApiSchema(
        TestResponse $response,
        ?HttpMethod $method = null,
        ?string $path = null,
    ): void {
        if (self::isAlreadyValidated($response)) {
            return;
        }
        self::markValidated($response);

        $specName = $this->resolveOpenApiSpec();
        if ($specName === '') {
            $this->fail(
                'openApiSpec() must return a non-empty spec name, but an empty string was returned. '
                . 'Either add #[OpenApiSpec(\'your-spec\')] to your test class or method, '
                . 'override openApiSpec() in your test class, or set the "default_spec" key '
                . 'in config/openapi-contract-testing.php.',
            );
        }

        $resolvedMethod = $method !== null ? $method->value : app('request')->getMethod();
        $resolvedPath = $path ?? app('request')->getPathInfo();

        $content = $response->getContent();
        if ($content === false) {
            $this->fail('OpenAPI contract testing requires buffered responses, but getContent() returned false (streamed response?).');
        }

        $contentType = $response->headers->get('Content-Type', '');

        $validator = self::getOrCreateValidator();
        $result = $validator->validate(
            $specName,
            $resolvedMethod,
            $resolvedPath,
            $response->getStatusCode(),
            $this->extractJsonBody($response, $content, $contentType),
            $contentType !== '' ? $contentType : null,
        );

        // Record coverage for any matched endpoint, including those where body
        // validation was skipped (e.g. non-JSON content types). "Covered" means
        // the endpoint was exercised in a test, not that its body was validated.
        if ($result->matchedPath() !== null) {
            OpenApiCoverageTracker::record(
                $specName,
                $resolvedMethod,
                $result->matchedPath(),
            );
        }

        $this->assertTrue(
            $result->isValid(),
            "OpenAPI schema validation failed for {$resolvedMethod} {$resolvedPath} (spec: {$specName}):\n"
            . $result->errorMessage(),
        );
    }

    private static function isAlreadyValidated(TestResponse $response): bool
    {
        return self::$validatedResponses !== null &&
            isset(self::$validatedResponses[$response]);
    }

    private static function markValidated(TestResponse $response): void
    {
        self::$validatedResponses ??= new WeakMap();
        self::$validatedResponses[$response] = true;
    }

    private static function getOrCreateValidator(): OpenApiResponseValidator
    {
        $maxErrors = config('openapi-contract-testing.max_errors', 20);
        $resolvedMaxErrors = is_numeric($maxErrors) ? (int) $maxErrors : 20;

        if (self::$cachedValidator === null || self::$cachedMaxErrors !== $resolvedMaxErrors) {
            self::$cachedValidator = new OpenApiResponseValidator(maxErrors: $resolvedMaxErrors);
            self::$cachedMaxErrors = $resolvedMaxErrors;
        }

        return self::$cachedValidator;
    }

    /** @return null|array<string, mixed> */
    private function extractJsonBody(TestResponse $response, string $content, string $contentType): ?array
    {
        if ($content === '') {
            return null;
        }

        // Non-JSON Content-Type: return null so the validator can decide
        // whether the spec requires a JSON body for this endpoint.
        if ($contentType !== '' && !str_contains(strtolower($contentType), 'json')) {
            return null;
        }

        try {
            return $response->json();
        } catch (JsonException $e) {
            $this->fail(
                'Response body could not be parsed as JSON: ' . $e->getMessage()
                . ($contentType === '' ? ' (no Content-Type header was present on the response)' : ''),
            );
        }
    }
}
