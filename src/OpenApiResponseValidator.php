<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

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
use function is_array;
use function preg_last_error_msg;
use function preg_match;
use function sprintf;
use function strtolower;

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

        // Skip-by-status-code: applied before the "Status code not defined"
        // branch so a configured skip suppresses both status-code-level failure
        // modes — "this code isn't in the spec's responses map" AND "this code
        // IS documented but the body doesn't match its schema". Earlier checks
        // (path / method not in spec) still fail loudly so typos stay visible.
        $matchingPattern = $this->matchingSkipPattern($statusCodeStr);
        if ($matchingPattern !== null) {
            // matchedStatusCode here is the literal HTTP status string, not a
            // spec key — see OpenApiValidationResult::skipped() for why.
            return OpenApiValidationResult::skipped(
                $matchedPath,
                sprintf('status %s matched skip pattern %s', $statusCodeStr, $matchingPattern),
                $statusCodeStr,
            );
        }

        if (!isset($responses[$statusCodeStr])) {
            return OpenApiValidationResult::failure([
                "Status code {$statusCode} not defined for {$method} {$matchedPath} in '{$specName}' spec.",
            ], $matchedPath);
        }

        $responseSpec = $responses[$statusCodeStr];

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
