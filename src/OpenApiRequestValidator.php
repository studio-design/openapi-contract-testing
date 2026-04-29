<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use Studio\OpenApiContractTesting\Validation\Request\HeaderParameterValidator;
use Studio\OpenApiContractTesting\Validation\Request\ParameterCollector;
use Studio\OpenApiContractTesting\Validation\Request\PathParameterValidator;
use Studio\OpenApiContractTesting\Validation\Request\QueryParameterValidator;
use Studio\OpenApiContractTesting\Validation\Request\RequestBodyValidator;
use Studio\OpenApiContractTesting\Validation\Request\SecurityValidator;
use Studio\OpenApiContractTesting\Validation\Support\SchemaValidatorRunner;
use Studio\OpenApiContractTesting\Validation\Support\ValidatorErrorBoundary;

use function array_keys;
use function implode;
use function strtolower;
use function strtoupper;

final class OpenApiRequestValidator
{
    /** @var array<string, OpenApiPathMatcher> */
    private array $pathMatchers = [];
    private readonly PathParameterValidator $pathValidator;
    private readonly QueryParameterValidator $queryValidator;
    private readonly HeaderParameterValidator $headerValidator;
    private readonly SecurityValidator $securityValidator;
    private readonly RequestBodyValidator $bodyValidator;

    public function __construct(int $maxErrors = 20)
    {
        $runner = new SchemaValidatorRunner($maxErrors);

        $this->pathValidator = new PathParameterValidator($runner);
        $this->queryValidator = new QueryParameterValidator($runner);
        $this->headerValidator = new HeaderParameterValidator($runner);
        $this->securityValidator = new SecurityValidator();
        $this->bodyValidator = new RequestBodyValidator($runner);
    }

    /**
     * Validate an incoming request against the OpenAPI spec.
     *
     * Composes path-parameter, query-parameter, header-parameter, security,
     * and request-body validation plus any spec-level errors surfaced while
     * collecting merged parameters, and returns a single result. Errors from
     * all sources are accumulated so a single test run surfaces every
     * contract drift the request exhibits.
     *
     * @param array<string, mixed> $queryParams parsed query string (string|array<string> per key)
     * @param array<array-key, mixed> $headers request headers (string|array<string> per key, case-insensitive name match; non-string keys are silently dropped)
     * @param array<string, mixed> $cookies request cookies (string values per key). Used for apiKey security schemes with `in: cookie`. Caller is expected to pass framework-parsed cookies (e.g. Laravel's `$request->cookies->all()`) — this validator does not parse a `Cookie` header.
     */
    public function validate(
        string $specName,
        string $method,
        string $requestPath,
        array $queryParams,
        array $headers,
        mixed $requestBody,
        ?string $contentType = null,
        array $cookies = [],
    ): OpenApiValidationResult {
        $spec = OpenApiSpecLoader::load($specName);

        $version = OpenApiVersion::fromSpec($spec);

        /** @var string[] $specPaths */
        $specPaths = array_keys($spec['paths'] ?? []);
        $matcher = $this->getPathMatcher($specName, $specPaths);
        $matched = $matcher->matchWithVariables($requestPath);

        if ($matched === null) {
            return OpenApiValidationResult::failure([
                self::formatPathNotFoundError($specName, $method, $requestPath, $matcher, $spec),
            ]);
        }

        $matchedPath = $matched['path'];
        $pathVariables = $matched['variables'];

        $lowerMethod = strtolower($method);
        /** @var array<string, mixed> $pathSpec */
        $pathSpec = $spec['paths'][$matchedPath] ?? [];

        if (!isset($pathSpec[$lowerMethod])) {
            return OpenApiValidationResult::failure([
                self::formatMethodNotDefinedError($specName, $method, $matchedPath, $spec),
            ], $matchedPath);
        }

        /** @var array<string, mixed> $operation */
        $operation = $pathSpec[$lowerMethod];

        // Collect merged path/operation parameters once so path + query + header
        // validation share a single view of the spec and malformed-entry errors
        // are surfaced only once.
        $collected = ParameterCollector::collect($method, $matchedPath, $pathSpec, $operation);

        // Each sub-validator is wrapped in ValidatorErrorBoundary::safely() so a
        // RuntimeException thrown from one (typically an opis/json-schema
        // SchemaException via body validation — e.g. InvalidKeywordException from a
        // malformed `pattern` keyword, or UnresolvedReferenceException from a $ref
        // the loader couldn't resolve) is converted to an error string instead of
        // aborting the orchestrator and discarding errors already collected from
        // sibling validators. \LogicException and \Error still bubble so programmer
        // bugs are not silently downgraded to "just another contract error".
        //
        // The boundary is per-sub-validator and permissive: a capture at one stage
        // does NOT short-circuit later stages — every sub-validator still runs so
        // a single test run surfaces as much contract drift as possible.
        $errors = [
            ...$collected->specErrors,
            ...ValidatorErrorBoundary::safely('path', $specName, $method, $matchedPath, fn(): array => $this->pathValidator->validate($method, $matchedPath, $collected->parameters, $pathVariables, $version)),
            ...ValidatorErrorBoundary::safely('query', $specName, $method, $matchedPath, fn(): array => $this->queryValidator->validate($method, $matchedPath, $collected->parameters, $queryParams, $version)),
            ...ValidatorErrorBoundary::safely('header', $specName, $method, $matchedPath, fn(): array => $this->headerValidator->validate($method, $matchedPath, $collected->parameters, $headers, $version)),
            ...ValidatorErrorBoundary::safely('security', $specName, $method, $matchedPath, fn(): array => $this->securityValidator->validate($method, $matchedPath, $spec, $operation, $headers, $queryParams, $cookies)),
            ...ValidatorErrorBoundary::safely('request-body', $specName, $method, $matchedPath, fn(): array => $this->bodyValidator->validate($specName, $method, $matchedPath, $operation, $requestBody, $contentType, $version)),
        ];

        if ($errors === []) {
            return OpenApiValidationResult::success($matchedPath);
        }

        return OpenApiValidationResult::failure($errors, $matchedPath);
    }

    /**
     * Mirrors OpenApiResponseValidator::formatPathNotFoundError. Duplicated
     * intentionally — both validators need the same diagnostic shape, but
     * extracting the helper would either add a new public class for two short
     * call sites or force a circular dependency through a shared trait.
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
        $defined = $methods === [] ? '(none)' : implode(', ', $methods);

        return "Method {$method} not defined for path {$matchedPath} in '{$specName}' spec. Defined methods: {$defined}.";
    }

    /**
     * @param string[] $specPaths
     */
    private function getPathMatcher(string $specName, array $specPaths): OpenApiPathMatcher
    {
        return $this->pathMatchers[$specName] ??= new OpenApiPathMatcher($specPaths, OpenApiSpecLoader::getStripPrefixes());
    }
}
