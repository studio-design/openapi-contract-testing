<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Support;

use Studio\OpenApiContractTesting\OpenApiPathMatcher;
use Studio\OpenApiContractTesting\OpenApiPathSuggester;

use function implode;
use function strtoupper;

/**
 * Composes the multi-line "no matching path" / single-line "method not
 * defined" diagnostics shared by {@see OpenApiResponseValidator} and
 * {@see OpenApiRequestValidator}. Lives alongside {@see ValidatorErrorBoundary}
 * because both serve the same purpose: keep cross-validator error wording in
 * one place so it cannot drift between request- and response-side surfaces.
 *
 * Formatting decisions worth knowing for callers:
 * - The "searched as:" callout fires only when {@see OpenApiPathMatcher::normalizeRequestPath()}
 *   reports a non-null `strippedPrefix`. Trailing-slash trimming alone is not
 *   surfaced — it's a universal normalization and adding a line for it would
 *   dilute the more useful prefix signal.
 * - The "closest spec paths:" section is omitted entirely when the suggester
 *   produces no candidates (empty / malformed spec).
 * - The "Defined methods:" suffix renders `(none)` when a path item declares
 *   only non-operation keys (`parameters`, `summary`, ...). Theoretically
 *   unreachable from the current call sites — the helper is only invoked when
 *   one specific method is missing, not all — but rendering a sentinel keeps
 *   a malformed spec visible instead of silently producing "Defined methods: .".
 */
final class PathDiagnosticsFormatter
{
    /**
     * @param array<string, mixed> $spec the decoded OpenAPI document, used to
     *                                   draw "did you mean?" candidates from
     */
    public static function pathNotFound(
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
    public static function methodNotDefined(
        string $specName,
        string $method,
        string $matchedPath,
        array $spec,
    ): string {
        $methods = OpenApiPathSuggester::methodsForPath($spec, $matchedPath);
        $defined = $methods === [] ? '(none)' : implode(', ', $methods);

        return "Method {$method} not defined for path {$matchedPath} in '{$specName}' spec. Defined methods: {$defined}.";
    }
}
