<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Laravel;

use const E_USER_DEPRECATED;
use const FILTER_NULL_ON_FAILURE;
use const FILTER_VALIDATE_BOOLEAN;
use const STDERR;

use Illuminate\Testing\TestResponse;
use JsonException;
use Studio\OpenApiContractTesting\HttpMethod;
use Studio\OpenApiContractTesting\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\OpenApiResponseValidator;
use Studio\OpenApiContractTesting\OpenApiSpecResolver;
use Studio\OpenApiContractTesting\SkipOpenApi;
use Studio\OpenApiContractTesting\SkipOpenApiResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use WeakMap;

use function filter_var;
use function fwrite;
use function get_debug_type;
use function is_numeric;
use function is_string;
use function sprintf;
use function str_contains;
use function strtolower;
use function strtoupper;
use function trigger_error;
use function var_export;

trait ValidatesOpenApiSchema
{
    use OpenApiSpecResolver;
    use SkipOpenApiResolver;
    private static ?OpenApiResponseValidator $cachedValidator = null;
    private static ?int $cachedMaxErrors = null;

    /** @var null|WeakMap<TestResponse, array<string, true>> */
    private static ?WeakMap $validatedResponses = null;

    /**
     * Receives the warning emitted when a test marked #[SkipOpenApi] still
     * calls assertResponseMatchesOpenApiSchema() explicitly. The explicit
     * assertion always runs regardless — this is an advisory nudge that the
     * two signals contradict each other.
     *
     * Defaults to writing to STDERR and emitting an E_USER_DEPRECATED via
     * trigger_error(). Tests can swap it to capture warnings in-memory.
     *
     * @var null|callable(string): void
     */
    private static $skipWarningHandler;

    public static function resetValidatorCache(): void
    {
        self::$cachedValidator = null;
        self::$cachedMaxErrors = null;
        self::$validatedResponses = null;
    }

    /**
     * Overrides Laravel's MakesHttpRequests::createTestResponse hook so every
     * HTTP test call runs schema validation when auto_assert is enabled.
     * When the library is used outside Laravel, this method is never called.
     *
     * Method and path are resolved from the Request passed in by Laravel
     * rather than from app('request'), so auto-assert stays independent of
     * container state and sees the exact values the framework dispatched.
     *
     * @param Response $response
     * @param null|Request $request
     */
    protected function createTestResponse($response, $request = null): TestResponse
    {
        $testResponse = parent::createTestResponse($response, $request);

        $method = $request !== null ? HttpMethod::tryFrom(strtoupper($request->getMethod())) : null;
        $path = $request?->getPathInfo();

        $this->maybeAutoAssertOpenApiSchema($testResponse, $method, $path);

        return $testResponse;
    }

    protected function maybeAutoAssertOpenApiSchema(
        TestResponse $response,
        ?HttpMethod $method = null,
        ?string $path = null,
    ): void {
        if (!$this->isAutoAssertEnabled()) {
            return;
        }

        // #[SkipOpenApi] opts the test out of auto-assert entirely — no
        // validation, no coverage recording. Explicit calls to
        // assertResponseMatchesOpenApiSchema() still run but emit a warning.
        if ($this->findSkipOpenApiAttribute() !== null) {
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
        $skipAttribute = $this->findSkipOpenApiAttribute();
        if ($skipAttribute !== null) {
            $this->emitSkipOpenApiWarning($skipAttribute);
        }

        $resolvedMethod = $method !== null ? $method->value : app('request')->getMethod();
        $resolvedPath = $path ?? app('request')->getPathInfo();

        $specName = $this->resolveOpenApiSpec();
        if ($specName === '') {
            $this->fail(
                'openApiSpec() must return a non-empty spec name, but an empty string was returned. '
                . 'Either add #[OpenApiSpec(\'your-spec\')] to your test class or method, '
                . 'override openApiSpec() in your test class, or set the "default_spec" key '
                . 'in config/openapi-contract-testing.php.',
            );
        }

        // Idempotency key includes the spec so that validating the same
        // response against a different spec (or a different method/path on
        // the same spec) still runs — auto-assert's no-op only applies to
        // exact repeats.
        $signature = $specName . ':' . $resolvedMethod . ' ' . $resolvedPath;

        if (self::isAlreadyValidated($response, $signature)) {
            return;
        }
        self::markValidated($response, $signature);

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
        // Note: under auto_assert, this records coverage for every Laravel HTTP
        // call — including responses with no explicit contract-test intent.
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

    private static function isAlreadyValidated(TestResponse $response, string $signature): bool
    {
        return self::$validatedResponses !== null &&
            isset(self::$validatedResponses[$response][$signature]);
    }

    private static function markValidated(TestResponse $response, string $signature): void
    {
        self::$validatedResponses ??= new WeakMap();
        $signatures = self::$validatedResponses[$response] ?? [];
        $signatures[$signature] = true;
        self::$validatedResponses[$response] = $signatures;
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

    private function emitSkipOpenApiWarning(SkipOpenApi $attribute): void
    {
        $reason = $attribute->reason;
        $message = sprintf(
            '%s::%s is marked #[SkipOpenApi%s] but called assertResponseMatchesOpenApiSchema() explicitly. '
            . 'The assertion will run. Remove the attribute or the explicit call to clarify intent.',
            static::class,
            $this->name(), // @phpstan-ignore method.notFound
            $reason !== '' ? sprintf('(reason: %s)', var_export($reason, true)) : '',
        );

        $handler = self::$skipWarningHandler;
        if ($handler !== null) {
            $handler($message);

            return;
        }

        // STDERR guarantees the message body is visible in CI regardless of
        // PHPUnit's `displayDetailsOnTestsThatTriggerDeprecations` setting —
        // without it, the default config would only show a "1 deprecation"
        // tally and hide the actual contradictory-intent message.
        fwrite(STDERR, sprintf("\n[openapi-contract-testing] %s\n", $message));
        // trigger_error still fires so PHPUnit counts the deprecation and
        // surfaces it in the run summary for downstream tools to detect.
        trigger_error($message, E_USER_DEPRECATED);
    }

    private function isAutoAssertEnabled(): bool
    {
        $raw = config('openapi-contract-testing.auto_assert', false);

        if ($raw === true) {
            return true;
        }
        if ($raw === false || $raw === null) {
            return false;
        }

        $parsed = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            $this->fail(sprintf(
                'openapi-contract-testing.auto_assert must be a boolean (or a boolean-compatible value '
                . 'like "true"/"false"/"1"/"0"), got %s: %s.',
                get_debug_type($raw),
                var_export($raw, true),
            ));
        }

        return $parsed;
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
