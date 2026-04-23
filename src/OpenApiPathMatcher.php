<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use function explode;
use function implode;
use function preg_match;
use function preg_quote;
use function rtrim;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;
use function usort;

final class OpenApiPathMatcher
{
    /** @var array{pattern: string, path: string, paramNames: string[], literalSegments: int}[] */
    private array $compiledPaths;

    /**
     * @param string[] $specPaths
     * @param string[] $stripPrefixes Prefixes to strip from request paths before matching (e.g., ['/api'])
     */
    public function __construct(
        array $specPaths,
        private readonly array $stripPrefixes = [],
    ) {
        $compiled = [];
        foreach ($specPaths as $specPath) {
            $segments = explode('/', trim($specPath, '/'));
            $literalCount = 0;
            $regexSegments = [];
            $paramNames = [];

            foreach ($segments as $segment) {
                if (preg_match('/^\{(.+)\}$/', $segment, $m)) {
                    $regexSegments[] = '([^/]+)';
                    $paramNames[] = $m[1];
                } else {
                    $regexSegments[] = preg_quote($segment, '#');
                    $literalCount++;
                }
            }

            $pattern = '#^/' . implode('/', $regexSegments) . '$#';
            $compiled[] = [
                'pattern' => $pattern,
                'path' => $specPath,
                'paramNames' => $paramNames,
                'literalSegments' => $literalCount,
            ];
        }

        // Sort by literal segment count descending so more specific paths match first
        usort($compiled, static fn(array $a, array $b): int => $b['literalSegments'] <=> $a['literalSegments']);

        $this->compiledPaths = $compiled;
    }

    public function match(string $requestPath): ?string
    {
        return $this->matchWithVariables($requestPath)['path'] ?? null;
    }

    /**
     * Match a request path and return both the matched spec path template and
     * the raw values captured for each `{placeholder}` segment.
     *
     * Values are returned **percent-encoded** (exactly as they appear in the
     * request URI). Callers that need to validate the decoded value should
     * apply `rawurldecode()` themselves — we don't decode here to keep the
     * matcher a pure string operation and leave encoding policy to the caller.
     *
     * @return null|array{path: string, variables: array<string, string>}
     */
    public function matchWithVariables(string $requestPath): ?array
    {
        $normalizedPath = $requestPath;

        foreach ($this->stripPrefixes as $prefix) {
            if (str_starts_with($normalizedPath, $prefix)) {
                $normalizedPath = substr($normalizedPath, strlen($prefix));
                break;
            }
        }

        // Strip trailing slash (but keep root /)
        if ($normalizedPath !== '/' && str_ends_with($normalizedPath, '/')) {
            $normalizedPath = rtrim($normalizedPath, '/');
        }

        foreach ($this->compiledPaths as $compiled) {
            if (preg_match($compiled['pattern'], $normalizedPath, $matches) !== 1) {
                continue;
            }

            $variables = [];
            foreach ($compiled['paramNames'] as $i => $name) {
                // $matches[0] is the full match; capture groups start at index 1.
                $variables[$name] = $matches[$i + 1];
            }

            return ['path' => $compiled['path'], 'variables' => $variables];
        }

        return null;
    }
}
