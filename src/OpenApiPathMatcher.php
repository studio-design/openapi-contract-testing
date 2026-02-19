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
    /** @var array{pattern: string, path: string, literalSegments: int}[] */
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

            foreach ($segments as $segment) {
                if (preg_match('/^\{.+\}$/', $segment)) {
                    $regexSegments[] = '[^/]+';
                } else {
                    $regexSegments[] = preg_quote($segment, '#');
                    $literalCount++;
                }
            }

            $pattern = '#^/' . implode('/', $regexSegments) . '$#';
            $compiled[] = [
                'pattern' => $pattern,
                'path' => $specPath,
                'literalSegments' => $literalCount,
            ];
        }

        // Sort by literal segment count descending so more specific paths match first
        usort($compiled, static fn(array $a, array $b): int => $b['literalSegments'] <=> $a['literalSegments']);

        $this->compiledPaths = $compiled;
    }

    public function match(string $requestPath): ?string
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
            if (preg_match($compiled['pattern'], $normalizedPath)) {
                return $compiled['path'];
            }
        }

        return null;
    }
}
