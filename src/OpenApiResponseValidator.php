<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use InvalidArgumentException;
use Studio\OpenApiContractTesting\Validation\Response\ResponseBodyValidator;
use Studio\OpenApiContractTesting\Validation\Support\SchemaValidatorRunner;
use Studio\OpenApiContractTesting\Validation\Support\ValidatorErrorBoundary;

use function array_keys;
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
        $this->bodyValidator = new ResponseBodyValidator(new SchemaValidatorRunner($maxErrors));
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

        // Skip-by-status-code: applied before the "Status code not defined"
        // branch so a configured skip suppresses both status-code-level failure
        // modes — "this code isn't in the spec's responses map" AND "this code
        // IS documented but the body doesn't match its schema". Earlier checks
        // (path / method not in spec) still fail loudly so typos stay visible.
        $matchingPattern = $this->matchingSkipPattern($statusCodeStr);
        if ($matchingPattern !== null) {
            return OpenApiValidationResult::skipped(
                $matchedPath,
                sprintf('status %s matched skip pattern %s', $statusCodeStr, $matchingPattern),
            );
        }

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

        // ValidatorErrorBoundary::safely() converts a RuntimeException thrown from
        // body validation (e.g. opis/json-schema SchemaException — InvalidKeywordException,
        // UnresolvedReferenceException, ...) into an error-string entry rather than
        // letting it abort the orchestrator. Preserves observability symmetry with
        // OpenApiRequestValidator. \LogicException and \Error still bubble so
        // programmer bugs are not masked.
        $errors = ValidatorErrorBoundary::safely(
            'response-body',
            $specName,
            $method,
            $matchedPath,
            fn(): array => $this->bodyValidator->validate(
                $specName,
                $method,
                $matchedPath,
                $statusCode,
                $content,
                $responseBody,
                $responseContentType,
                $version,
            ),
        );

        if ($errors === []) {
            return OpenApiValidationResult::success($matchedPath);
        }

        return OpenApiValidationResult::failure($errors, $matchedPath);
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
