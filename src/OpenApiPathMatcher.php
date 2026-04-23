<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use InvalidArgumentException;

use function explode;
use function implode;
use function in_array;
use function preg_match;
use function preg_quote;
use function rtrim;
use function sprintf;
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
                    // OpenAPI forbids duplicate placeholder names in a single template.
                    // If we silently overwrote the earlier capture, one of the segments
                    // would skip validation depending on position — a direction-dependent
                    // silent pass. Refuse to compile instead.
                    if (in_array($m[1], $paramNames, true)) {
                        throw new InvalidArgumentException(sprintf(
                            "Duplicate path placeholder name '%s' in spec path '%s'. OpenAPI requires unique placeholder names within a single template.",
                            $m[1],
                            $specPath,
                        ));
                    }

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
     * Values are returned exactly as they appeared in `$requestPath` — no
     * decoding is performed. When the caller passes the raw request URI (the
     * intended use, e.g. via Symfony's `Request::getPathInfo()` which returns
     * the un-decoded path), the captured values will be percent-encoded and
     * the caller should apply `rawurldecode()` before validating. Keeping
     * encoding policy in one place on the caller side avoids the double-decode
     * hazard that would arise if we decoded here.
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
