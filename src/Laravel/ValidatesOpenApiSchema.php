<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Laravel;

use const E_USER_DEPRECATED;
use const FILTER_NULL_ON_FAILURE;
use const FILTER_VALIDATE_BOOLEAN;
use const STDERR;

use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
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

use function array_merge;
use function filter_var;
use function fwrite;
use function get_debug_type;
use function is_array;
use function is_int;
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

    /** @var null|string[] */
    private static ?array $cachedSkipResponseCodes = null;

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

    // Per-request skip flags set by withoutRequestValidation() /
    // withoutResponseValidation() / withoutValidation(). Consumed (and reset)
    // on the next auto-assert attempt, so the flag covers exactly one HTTP
    // call. Instance-level because PHPUnit builds a fresh TestCase per
    // method — this gives us natural per-test isolation.
    private bool $skipNextRequestValidation = false;
    private bool $skipNextResponseValidation = false;

    // Per-request additional response-code skip patterns set by
    // skipResponseCode(). Merged with the config-level skip set when building
    // the validator, then consumed (reset) on the next auto-assert attempt.
    // Patterns are stored raw (without delimiters/anchors); the validator
    // anchors them when compiling.
    /** @var string[] */
    private array $skipNextResponseCodes = [];

    public static function resetValidatorCache(): void
    {
        self::$cachedValidator = null;
        self::$cachedMaxErrors = null;
        self::$cachedSkipResponseCodes = null;
        self::$validatedResponses = null;
    }

    /**
     * Skips both request and response validation for the next HTTP call only.
     * The flag self-resets after one auto-assert attempt, so subsequent calls
     * are validated as usual.
     *
     * Scoped to auto-assert only — explicit calls to
     * assertResponseMatchesOpenApiSchema() still run, matching the convention
     * already established by #[SkipOpenApi].
     */
    public function withoutValidation(): static
    {
        $this->skipNextRequestValidation = true;
        $this->skipNextResponseValidation = true;

        return $this;
    }

    /**
     * Skips request validation for the next HTTP call only. Currently a
     * forward-looking hook: request validation itself lands in #43, and this
     * method wires up the flag ahead of time so callers can already write the
     * intended API surface.
     */
    public function withoutRequestValidation(): static
    {
        $this->skipNextRequestValidation = true;

        return $this;
    }

    /**
     * Skips response validation for the next HTTP call only. The flag
     * self-resets after one auto-assert attempt.
     *
     * Scoped to auto-assert only, matching the convention established by
     * #[SkipOpenApi] and `withoutValidation()`.
     */
    public function withoutResponseValidation(): static
    {
        $this->skipNextResponseValidation = true;

        return $this;
    }

    /**
     * Adds one or more response status codes to skip for the next auto-assert
     * HTTP call only. Merged with the config-level `skip_response_codes` set,
     * then consumed (reset) after one call — matching the per-request
     * consumption model of withoutValidation().
     *
     * - int: exact match (anchored for exact match, so `500` matches only "500").
     * - string: regex pattern (anchored automatically).
     * - array: expanded one level; each element must be int or string.
     *   Nested arrays are rejected with a clear error message rather than a
     *   raw TypeError.
     *
     * Scoped to auto-assert only. Explicit calls to
     * assertResponseMatchesOpenApiSchema() emit an advisory warning because
     * the per-request flag is silently ignored there.
     *
     * @param array<int|string>|int|string ...$codes
     *
     * @throws InvalidArgumentException when no codes are supplied, when an
     *                                  array argument is nested, or when an
     *                                  array element is not int|string.
     */
    public function skipResponseCode(array|int|string ...$codes): static
    {
        if ($codes === []) {
            throw new InvalidArgumentException(
                'skipResponseCode() requires at least one code.',
            );
        }

        $normalized = [];
        foreach ($codes as $index => $code) {
            if (is_array($code)) {
                foreach ($code as $innerIndex => $inner) {
                    if (!is_int($inner) && !is_string($inner)) {
                        throw new InvalidArgumentException(sprintf(
                            'skipResponseCode() array elements must be int or string; got %s at position [%d][%s]. '
                            . 'Nested arrays are not supported.',
                            get_debug_type($inner),
                            $index,
                            (string) $innerIndex,
                        ));
                    }
                    $normalized[] = self::normalizeSkipCode($inner);
                }
            } else {
                $normalized[] = self::normalizeSkipCode($code);
            }
        }

        if ($normalized === []) {
            throw new InvalidArgumentException(
                'skipResponseCode() requires at least one code, but all supplied arrays were empty.',
            );
        }

        foreach ($normalized as $code) {
            $this->skipNextResponseCodes[] = $code;
        }

        return $this;
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
        // Consume per-request skip flags unconditionally, so they track the
        // HTTP call boundary regardless of auto_assert state. Without this,
        // a flag set before an auto_assert=false call would silently leak
        // into the next call after auto_assert flips on.
        $skipResponse = $this->skipNextResponseValidation;
        $extraSkipCodes = $this->skipNextResponseCodes;
        $this->skipNextRequestValidation = false;
        $this->skipNextResponseValidation = false;
        $this->skipNextResponseCodes = [];

        if (!$this->isAutoAssertEnabled()) {
            return;
        }

        if ($skipResponse) {
            return;
        }

        // #[SkipOpenApi] opts the test out of auto-assert entirely — no
        // validation, no coverage recording. Explicit calls to
        // assertResponseMatchesOpenApiSchema() still run but emit a warning.
        if ($this->findSkipOpenApiAttribute() !== null) {
            return;
        }

        $this->assertResponseMatchesOpenApiSchema($response, $method, $path, $extraSkipCodes);
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

    /**
     * @param string[] $extraSkipResponseCodes Additional skip patterns for
     *                                         this call only; populated by maybeAutoAssertOpenApiSchema().
     *                                         Empty for explicit user calls.
     */
    protected function assertResponseMatchesOpenApiSchema(
        TestResponse $response,
        ?HttpMethod $method = null,
        ?string $path = null,
        array $extraSkipResponseCodes = [],
    ): void {
        $skipAttribute = $this->findSkipOpenApiAttribute();
        if ($skipAttribute !== null) {
            $this->emitSkipOpenApiWarning($skipAttribute);
        }

        // Pending per-request skip codes with no auto-assert in sight: the
        // user set skipResponseCode() but is calling explicit assert, which
        // ignores the flag by design. Warn and consume so the flag doesn't
        // leak into a later HTTP call and surprise the user.
        if ($extraSkipResponseCodes === [] && $this->skipNextResponseCodes !== []) {
            $this->emitSkipResponseCodeWarning();
            $this->skipNextResponseCodes = [];
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

        // One-off validator when per-request skip codes are present — bypasses
        // the static cache so test-local codes don't pollute it (and so
        // cache-entry churn can't grow unbounded across tests).
        $validator = $extraSkipResponseCodes !== []
            ? $this->buildOneOffValidator($extraSkipResponseCodes)
            : $this->getOrCreateValidator();
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

    private static function normalizeSkipCode(int|string $code): string
    {
        // Int codes are returned as a bare string so the existing
        // OpenApiResponseValidator::compileSkipPatterns() pipeline wraps them
        // in ^(?:...)$ for exact-match semantics. Strings are already regex.
        return is_int($code) ? (string) $code : $code;
    }

    private function getOrCreateValidator(): OpenApiResponseValidator
    {
        $resolvedMaxErrors = $this->resolveMaxErrors();
        $resolvedSkipCodes = $this->resolveSkipResponseCodes();

        if (
            self::$cachedValidator === null ||
            self::$cachedMaxErrors !== $resolvedMaxErrors ||
            self::$cachedSkipResponseCodes !== $resolvedSkipCodes
        ) {
            self::$cachedValidator = new OpenApiResponseValidator(
                maxErrors: $resolvedMaxErrors,
                skipResponseCodes: $resolvedSkipCodes,
            );
            self::$cachedMaxErrors = $resolvedMaxErrors;
            self::$cachedSkipResponseCodes = $resolvedSkipCodes;
        }

        return self::$cachedValidator;
    }

    /**
     * @param string[] $extraSkipResponseCodes
     */
    private function buildOneOffValidator(array $extraSkipResponseCodes): OpenApiResponseValidator
    {
        return new OpenApiResponseValidator(
            maxErrors: $this->resolveMaxErrors(),
            skipResponseCodes: array_merge(
                $this->resolveSkipResponseCodes(),
                $extraSkipResponseCodes,
            ),
        );
    }

    private function resolveMaxErrors(): int
    {
        $maxErrors = config('openapi-contract-testing.max_errors', 20);

        return is_numeric($maxErrors) ? (int) $maxErrors : 20;
    }

    /** @return string[] */
    private function resolveSkipResponseCodes(): array
    {
        $raw = config('openapi-contract-testing.skip_response_codes', OpenApiResponseValidator::DEFAULT_SKIP_RESPONSE_CODES);

        if (!is_array($raw)) {
            $this->fail(sprintf(
                'openapi-contract-testing.skip_response_codes must be an array of regex patterns, got %s: %s.',
                get_debug_type($raw),
                var_export($raw, true),
            ));
        }

        $patterns = [];
        foreach ($raw as $index => $pattern) {
            if (!is_string($pattern)) {
                $this->fail(sprintf(
                    'openapi-contract-testing.skip_response_codes[%s] must be a string regex pattern, got %s.',
                    (string) $index,
                    get_debug_type($pattern),
                ));
            }
            if ($pattern === '') {
                $this->fail(sprintf(
                    'openapi-contract-testing.skip_response_codes[%s] must not be an empty string.',
                    (string) $index,
                ));
            }
            $patterns[] = $pattern;
        }

        return $patterns;
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

        $this->dispatchSkipWarning($message);
    }

    private function emitSkipResponseCodeWarning(): void
    {
        $message = sprintf(
            '%s::%s set skipResponseCode() before calling assertResponseMatchesOpenApiSchema() explicitly. '
            . 'Per-request skip codes apply only to auto-assert; the explicit assertion ignores them. '
            . 'Remove the skipResponseCode() call or rely on auto-assert to clarify intent.',
            static::class,
            $this->name(), // @phpstan-ignore method.notFound
        );

        $this->dispatchSkipWarning($message);
    }

    private function dispatchSkipWarning(string $message): void
    {
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
