<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use const E_USER_WARNING;

use InvalidArgumentException;
use RuntimeException;
use Studio\OpenApiContractTesting\Validation\Response\ResponseBodyValidationResult;
use Studio\OpenApiContractTesting\Validation\Response\ResponseBodyValidator;
use Studio\OpenApiContractTesting\Validation\Response\ResponseHeaderValidator;
use Studio\OpenApiContractTesting\Validation\Support\SchemaValidatorRunner;
use Studio\OpenApiContractTesting\Validation\Support\ValidatorErrorBoundary;

use function array_keys;
use function array_merge;
use function get_debug_type;
use function implode;
use function is_array;
use function preg_last_error_msg;
use function preg_match;
use function sprintf;
use function strtolower;
use function strtoupper;
use function trigger_error;

final class OpenApiResponseValidator
{
    /**
     * Regex patterns (without delimiters or anchors) that match response status
     * codes which should skip body validation. The default of `5\d\d` reflects
     * the common convention of not documenting production 5xx in specs.
     */
    public const DEFAULT_SKIP_RESPONSE_CODES = ['5\d\d'];

    /** @var array<string, OpenApiPathMatcher> */
    private array $pathMatchers = [];
    private readonly ResponseBodyValidator $bodyValidator;
    private readonly ResponseHeaderValidator $headerValidator;

    /** @var array<string, string> Raw pattern (as supplied) => anchored pattern ready for preg_match. */
    private readonly array $skipPatterns;

    /**
     * @param string[] $skipResponseCodes Regex patterns (without delimiters or
     *                                    anchors) matched against the response status code as a string. A hit
     *                                    short-circuits validation and returns an `OpenApiValidationResult::skipped()`
     *                                    — isValid() stays true, isSkipped() becomes true, and the matched
     *                                    path is still reported so coverage is recorded.
     */
    public function __construct(
        int $maxErrors = 20,
        array $skipResponseCodes = self::DEFAULT_SKIP_RESPONSE_CODES,
    ) {
        $this->skipPatterns = self::compileSkipPatterns($skipResponseCodes);
        $runner = new SchemaValidatorRunner($maxErrors);
        $this->bodyValidator = new ResponseBodyValidator($runner);
        $this->headerValidator = new ResponseHeaderValidator($runner);
    }

    /**
     * @param null|array<array-key, mixed> $responseHeaders the response's actual headers
     *                                                      (as returned by HeaderBag::all() — a map of name to list-of-values
     *                                                      or to a single string). When null, header validation is skipped
     *                                                      entirely; pass `[]` to validate against a spec that requires
     *                                                      headers but the response sent none.
     */
    public function validate(
        string $specName,
        string $method,
        string $requestPath,
        int $statusCode,
        mixed $responseBody,
        ?string $responseContentType = null,
        ?array $responseHeaders = null,
    ): OpenApiValidationResult {
        $spec = OpenApiSpecLoader::load($specName);

        $version = OpenApiVersion::fromSpec($spec);

        /** @var string[] $specPaths */
        $specPaths = array_keys($spec['paths'] ?? []);
        $matcher = $this->getPathMatcher($specName, $specPaths);
        $matchedPath = $matcher->match($requestPath);

        if ($matchedPath === null) {
            return OpenApiValidationResult::failure([
                self::formatPathNotFoundError($specName, $method, $requestPath, $matcher, $spec),
            ]);
        }

        $lowerMethod = strtolower($method);
        $pathSpec = $spec['paths'][$matchedPath] ?? [];

        if (!isset($pathSpec[$lowerMethod])) {
            return OpenApiValidationResult::failure([
                self::formatMethodNotDefinedError($specName, $method, $matchedPath, $spec),
            ], $matchedPath);
        }

        $statusCodeStr = (string) $statusCode;
        $responses = $pathSpec[$lowerMethod]['responses'] ?? [];

        // Skip-by-status-code: applied before the "Status code not defined"
        // branch so a configured skip suppresses both status-code-level failure
        // modes — "this code isn't in the spec's responses map" AND "this code
        // IS documented but the body doesn't match its schema". Earlier checks
        // (path / method not in spec) still fail loudly so typos stay visible.
        $matchingPattern = $this->matchingSkipPattern($statusCodeStr);
        if ($matchingPattern !== null) {
            // matchedStatusCode here is the literal HTTP status string, not a
            // spec key. Skip happens BEFORE key resolution (resolveResponseKey
            // runs further down), so we don't yet know which spec key would
            // have matched — and even when the spec only declares `default`
            // or a `5XX` range, callers that gate on isSkipped() expect the
            // wire status, not the resolved spec key. The coverage tracker's
            // statusKeyMatches() reconciles literal-vs-range at compute time.
            return OpenApiValidationResult::skipped(
                $matchedPath,
                sprintf('status %s matched skip pattern %s', $statusCodeStr, $matchingPattern),
                $statusCodeStr,
            );
        }

        // Spec lookup priority per OpenAPI 3.0/3.1:
        //   1. Exact code match (e.g. spec declares "503", response is 503)
        //   2. Range key match (e.g. spec declares "5XX", response is 503)
        //   3. `default` catch-all
        // Explicit codes take precedence over range keys; range keys take
        // precedence over `default`. Without this fallback, a spec that
        // documents only `default` (or only `5XX`) would fail every real
        // status — both patterns are common (Problem Details responses
        // typically use `default` for the error envelope).
        $matchedResponseKey = self::resolveResponseKey($specName, $method, $matchedPath, $responses, $statusCodeStr);
        if ($matchedResponseKey === null) {
            return OpenApiValidationResult::failure([
                "Status code {$statusCode} not defined for {$method} {$matchedPath} in '{$specName}' spec.",
            ], $matchedPath);
        }

        // Coverage tracking records under the spec key actually matched
        // (e.g. "5XX" or "default"), not the literal status — that lets
        // the renderer surface the spec's intent rather than the wire value.
        $statusCodeStr = $matchedResponseKey;
        $responseSpec = $responses[$matchedResponseKey];

        $bodyResult = $this->validateBody(
            $specName,
            $method,
            $matchedPath,
            $statusCode,
            $responseSpec,
            $responseBody,
            $responseContentType,
            $version,
        );

        $headerErrors = $this->validateHeaders(
            $specName,
            $method,
            $matchedPath,
            $responseSpec,
            $responseHeaders,
            $version,
        );

        // The body validator returns ([], null) for two distinct cases:
        // (a) 204-style — spec has no `content` block; nothing to validate,
        //     legitimately Success.
        // (b) Spec declares only non-JSON content types (e.g. `text/plain`)
        //     and we have no schema engine for them; the result is "we
        //     didn't actually check anything". Without this branch the
        //     orchestrator would mark the response as a clean Success and
        //     coverage would credit the spec's declared content-type as
        //     validated even though no validation occurred.
        // Distinguishing them requires looking at the spec — `content`
        // present + non-empty + bodyResult.matchedContentType null + body
        // had no errors → case (b).
        $hasContentBlock = isset($responseSpec['content']) && is_array($responseSpec['content']) && $responseSpec['content'] !== [];
        if ($bodyResult->errors === [] && $bodyResult->matchedContentType === null && $hasContentBlock && $headerErrors === []) {
            return OpenApiValidationResult::skipped(
                $matchedPath,
                'spec declares only non-JSON content types and the validator has no schema engine for them',
                $statusCodeStr,
            );
        }

        // Order is body errors first, headers second. Tests that pin
        // specific positions rely on this; reordering would silently
        // change diagnostic flow without breaking behaviour.
        $errors = array_merge($bodyResult->errors, $headerErrors);

        if ($errors === []) {
            return OpenApiValidationResult::success(
                $matchedPath,
                $statusCodeStr,
                $bodyResult->matchedContentType,
            );
        }

        return OpenApiValidationResult::failure(
            $errors,
            $matchedPath,
            $statusCodeStr,
            $bodyResult->matchedContentType,
        );
    }

    /**
     * Compose the "no matching path" diagnostic. Multi-line so the structured
     * pieces (stripped path, suggestions) stay visually separate from the raw
     * request URI on the lead line.
     *
     * @param array<string, mixed> $spec
     */
    private static function formatPathNotFoundError(
        string $specName,
        string $method,
        string $requestPath,
        OpenApiPathMatcher $matcher,
        array $spec,
    ): string {
        $upperMethod = strtoupper($method);
        $normalized = $matcher->normalizeRequestPath($requestPath);
        $suggestions = OpenApiPathSuggester::suggest($spec, $normalized['path']);

        $lines = ["No matching path found in '{$specName}' spec for {$upperMethod} {$requestPath}"];

        // Only mention the stripping when it actually changed the path.
        // Trailing-slash trim alone is not surfaced — it's universal and
        // adding a line for it dilutes the more useful prefix signal.
        if ($normalized['strippedPrefix'] !== null) {
            $lines[] = "  searched as: {$normalized['path']} (after stripping prefix '{$normalized['strippedPrefix']}')";
        }

        if ($suggestions !== []) {
            $lines[] = '  closest spec paths:';
            foreach ($suggestions as $s) {
                $lines[] = "    - {$s['method']} {$s['path']}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $spec
     */
    private static function formatMethodNotDefinedError(
        string $specName,
        string $method,
        string $matchedPath,
        array $spec,
    ): string {
        $methods = OpenApiPathSuggester::methodsForPath($spec, $matchedPath);
        // The "(none)" branch is theoretically unreachable from this call site
        // (we got here because a method-keyed entry was missing for one
        // specific method, not because every method was missing). Surface it
        // anyway so a malformed spec doesn't render an empty list.
        $defined = $methods === [] ? '(none)' : implode(', ', $methods);

        return "Method {$method} not defined for path {$matchedPath} in '{$specName}' spec. Defined methods: {$defined}.";
    }

    /**
     * Keys keyed by the user-provided pattern (raw, without delimiters/anchors)
     * so skipReason can echo what the caller wrote rather than the internal
     * anchored form.
     *
     * @param string[] $patterns
     *
     * @return array<string, string> raw pattern => anchored pattern
     */
    private static function compileSkipPatterns(array $patterns): array
    {
        $compiled = [];

        foreach ($patterns as $index => $pattern) {
            if ($pattern === '') {
                throw new InvalidArgumentException(
                    sprintf('skipResponseCodes[%s] must not be an empty string.', (string) $index),
                );
            }

            $anchored = '/^(?:' . $pattern . ')$/';

            $ok = @preg_match($anchored, '');
            if ($ok === false) {
                throw new InvalidArgumentException(
                    sprintf(
                        'skipResponseCodes[%s] is not a valid regex pattern "%s": %s',
                        (string) $index,
                        $pattern,
                        preg_last_error_msg(),
                    ),
                );
            }

            $compiled[$pattern] = $anchored;
        }

        return $compiled;
    }

    /**
     * Resolve a literal HTTP status to the spec's response key, applying
     * the conventional three-tier fallback shared by major OpenAPI tools:
     * exact match → range key → `default`. Returns the matched spec key
     * or null when no rule matches.
     *
     * Range keys are accepted in two casings only: `1XX`/`2XX`/`3XX`/`4XX`/`5XX`
     * (uppercase) or `1xx`/`2xx`/`3xx`/`4xx`/`5xx` (lowercase). Mixed-case
     * forms (`5Xx`, `5xX`) are intentionally rejected — the OpenAPI spec
     * uses uppercase consistently in examples and the lowercase variant is
     * a tolerated convention; permitting arbitrary case would silently mask
     * spec-author typos that look like range keys.
     *
     * Returns the spec author's literal key so coverage / error messages
     * reflect what they wrote.
     *
     * @param array<string, mixed> $responses
     */
    private static function resolveResponseKey(string $specName, string $method, string $matchedPath, array $responses, string $statusCodeStr): ?string
    {
        if (isset($responses[$statusCodeStr])) {
            return $statusCodeStr;
        }

        // Range key match — preserve the spec author's exact casing.
        if (preg_match('/^[1-5][0-9]{2}$/', $statusCodeStr) === 1) {
            $leadingDigit = $statusCodeStr[0];
            foreach (array_keys($responses) as $key) {
                // PHP auto-coerces numeric string keys (e.g. "200") to int
                // when used as array keys, so cast back to string before
                // the regex. Range keys like "5XX" are non-numeric and
                // unaffected.
                $keyStr = (string) $key;
                if (preg_match('/^([1-5])(?:XX|xx)$/', $keyStr, $m) === 1 && $m[1] === $leadingDigit) {
                    return $keyStr;
                }
            }
        }

        if (isset($responses['default'])) {
            // Before silently falling back to `default`, surface any keys
            // that LOOK like attempted spec keys but don't satisfy the
            // exact / range / default form. Without this warning, a typo
            // like `'40'` (truncated 404) or `'Default'` (wrong case)
            // alongside a `default` entry would silently route every
            // unmatched status to the default schema — masking the dogfood
            // signal "your spec doesn't actually cover this status".
            self::warnSuspiciousResponseKeys($specName, $method, $matchedPath, $responses);

            return 'default';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $responses
     */
    private static function warnSuspiciousResponseKeys(string $specName, string $method, string $matchedPath, array $responses): void
    {
        foreach (array_keys($responses) as $key) {
            $keyStr = (string) $key;
            if ($keyStr === 'default') {
                continue;
            }
            if (preg_match('/^[1-5][0-9]{2}$/', $keyStr) === 1) {
                continue;
            }
            if (preg_match('/^[1-5](?:XX|xx)$/', $keyStr) === 1) {
                continue;
            }

            trigger_error(
                sprintf(
                    "[OpenAPI] spec '%s' %s %s: response key '%s' is not a valid HTTP status, range key (1XX-5XX / 1xx-5xx), or 'default'; falling back to 'default' may be hiding a typo.",
                    $specName,
                    $method,
                    $matchedPath,
                    $keyStr,
                ),
                E_USER_WARNING,
            );
        }
    }

    /**
     * @param array<string, mixed> $responseSpec
     */
    private function validateBody(
        string $specName,
        string $method,
        string $matchedPath,
        int $statusCode,
        array $responseSpec,
        mixed $responseBody,
        ?string $responseContentType,
        OpenApiVersion $version,
    ): ResponseBodyValidationResult {
        // 204 No Content (and similar) declare no `content` block. Nothing
        // to validate — return empty so the result aggregates cleanly.
        if (!isset($responseSpec['content'])) {
            return new ResponseBodyValidationResult([], null);
        }

        /** @var array<string, array<string, mixed>> $content */
        $content = $responseSpec['content'];

        // Inlined try/catch mirrors ValidatorErrorBoundary::safely() for the
        // body validator: same narrow `RuntimeException` catch, same error
        // formatting. The boundary returns string[]; the body validator now
        // returns a richer DTO carrying matchedContentType, so we can't reuse
        // the helper as-is. \LogicException and \Error still bubble.
        try {
            return $this->bodyValidator->validate(
                $specName,
                $method,
                $matchedPath,
                $statusCode,
                $content,
                $responseBody,
                $responseContentType,
                $version,
            );
        } catch (RuntimeException $e) {
            $previous = $e->getPrevious();
            $previousSuffix = $previous !== null
                ? sprintf(' (caused by %s: %s)', $previous::class, $previous->getMessage())
                : '';

            return new ResponseBodyValidationResult(
                [sprintf(
                    "[%s] %s %s in '%s' spec: %s threw: %s%s",
                    'response-body',
                    $method,
                    $matchedPath,
                    $specName,
                    $e::class,
                    $e->getMessage(),
                    $previousSuffix,
                )],
                null,
            );
        }
    }

    /**
     * @param array<string, mixed> $responseSpec
     * @param null|array<array-key, mixed> $responseHeaders
     *
     * @return string[]
     */
    private function validateHeaders(
        string $specName,
        string $method,
        string $matchedPath,
        array $responseSpec,
        ?array $responseHeaders,
        OpenApiVersion $version,
    ): array {
        // Header validation is opt-in: callers that pre-date the parameter
        // (or framework-agnostic adapters that never see headers) pass null
        // and get the historical body-only behaviour. An explicit empty
        // array means "the response has no headers" and still triggers
        // required-header checks against the spec.
        if ($responseHeaders === null) {
            return [];
        }

        if (!isset($responseSpec['headers'])) {
            return [];
        }

        $headersSpec = $responseSpec['headers'];

        // A `headers` block that decoded to a non-mapping is a malformed
        // spec (e.g. YAML scalar where an object was expected). Surface
        // it as an error so the spec author notices instead of getting
        // a silent pass that hides every header from validation.
        if (!is_array($headersSpec)) {
            return [sprintf(
                "[response-header] spec 'headers' must be an object for %s %s; got %s.",
                $method,
                $matchedPath,
                get_debug_type($headersSpec),
            )];
        }

        if ($headersSpec === []) {
            return [];
        }

        /** @var array<string, mixed> $headersSpec */
        return ValidatorErrorBoundary::safely(
            'response-header',
            $specName,
            $method,
            $matchedPath,
            fn(): array => $this->headerValidator->validate($headersSpec, $responseHeaders, $version),
        );
    }

    /**
     * Returns the raw pattern (as supplied by the caller) that matched, or
     * null if no pattern matched. `preg_match` returning false (runtime
     * failure) is impossible in practice because compileSkipPatterns already
     * probed each pattern successfully against the empty string and the
     * subject here is always a short status-code string.
     */
    private function matchingSkipPattern(string $statusCode): ?string
    {
        foreach ($this->skipPatterns as $raw => $anchored) {
            if (preg_match($anchored, $statusCode) === 1) {
                // PHP coerces numeric-string array keys to int (e.g. the
                // pattern "500" lands under key 500). Cast back to string so
                // the documented ?string return type is honoured even for
                // status-code-shaped patterns.
                return (string) $raw;
            }
        }

        return null;
    }

    /**
     * @param string[] $specPaths
     */
    private function getPathMatcher(string $specName, array $specPaths): OpenApiPathMatcher
    {
        return $this->pathMatchers[$specName] ??= new OpenApiPathMatcher($specPaths, OpenApiSpecLoader::getStripPrefixes());
    }
}
