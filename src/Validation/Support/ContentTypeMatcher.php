<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Support;

use function count;
use function explode;
use function str_ends_with;
use function strstr;
use function strtolower;
use function trim;

final class ContentTypeMatcher
{
    /**
     * Find the first JSON-compatible content type key in the spec's
     * `content` map.
     *
     * Resolution priority (most-specific first):
     *   1. Literal `application/json` or any `+json` structured-syntax suffix (RFC 6838)
     *   2. `application/*` or any other `<type>/*` range that could match a JSON body
     *   3. `*&#47;*` full wildcard
     *
     * Wildcard support exists because OpenAPI 3.x §4.7.10 allows media-type
     * ranges as content keys; the pre-1.0 implementation matched literally
     * only, silently skipping JSON validation when the spec used ranges.
     * Matching is case-insensitive.
     *
     * @param array<string, mixed> $content
     */
    public static function findJsonContentType(array $content): ?string
    {
        // Pass 1: literal JSON keys.
        foreach ($content as $contentType => $_mediaType) {
            if (self::isJsonContentType(strtolower((string) $contentType))) {
                return (string) $contentType;
            }
        }

        // Pass 2: `application/*` and other type-ranges. Prefer non-`*&#47;*`
        // ranges because they constrain the type half.
        foreach ($content as $contentType => $_mediaType) {
            $lower = strtolower((string) $contentType);
            if (self::isTypeRange($lower) && $lower !== '*/*') {
                return (string) $contentType;
            }
        }

        // Pass 3: `*&#47;*`.
        foreach ($content as $contentType => $_mediaType) {
            if (strtolower((string) $contentType) === '*/*') {
                return (string) $contentType;
            }
        }

        return null;
    }

    /**
     * Extract the media type portion before any parameters (e.g. charset),
     * and return it lower-cased.
     *
     * Example: "text/html; charset=utf-8" → "text/html"
     */
    public static function normalizeMediaType(string $contentType): string
    {
        $mediaType = strstr($contentType, ';', true);

        return strtolower(trim($mediaType !== false ? $mediaType : $contentType));
    }

    /**
     * Check whether the given (already normalised, lower-cased) content type
     * matches any content type key defined in the spec. Spec keys are
     * lower-cased before comparison.
     *
     * @param array<string, mixed> $content
     */
    public static function isContentTypeInSpec(string $normalizedContentType, array $content): bool
    {
        return self::findContentTypeKey($normalizedContentType, $content) !== null;
    }

    /**
     * Return the spec key (with the spec author's original casing) whose
     * lower-cased form matches the given normalised content type, or null
     * when no spec key matches. Used by coverage tracking to surface the
     * spec's literal media-type keys (which may mix casings, e.g. petstore
     * declares both `Application/Problem+JSON` and `application/problem+json`).
     *
     * Resolution priority (most-specific first):
     *   1. Exact case-insensitive match
     *   2. `<type>/*` range matching `<type>/<subtype>` (e.g. `application/*`)
     *   3. `*&#47;*` full wildcard
     *
     * @param array<string, mixed> $content
     */
    public static function findContentTypeKey(string $normalizedContentType, array $content): ?string
    {
        // Pass 1: exact match.
        foreach ($content as $specContentType => $_mediaType) {
            if (strtolower((string) $specContentType) === $normalizedContentType) {
                return (string) $specContentType;
            }
        }

        // Pass 2: type-range match (`application/*` for `application/json`).
        $type = self::primaryType($normalizedContentType);
        if ($type !== null) {
            $rangeKey = $type . '/*';
            foreach ($content as $specContentType => $_mediaType) {
                if (strtolower((string) $specContentType) === $rangeKey) {
                    return (string) $specContentType;
                }
            }
        }

        // Pass 3: full wildcard.
        foreach ($content as $specContentType => $_mediaType) {
            if (strtolower((string) $specContentType) === '*/*') {
                return (string) $specContentType;
            }
        }

        return null;
    }

    /**
     * True for "application/json" or any "+json" structured syntax suffix (RFC 6838).
     * Expects a lower-cased media type without parameters.
     *
     * This is intentionally a literal check — wildcards are NOT JSON. The
     * actual response/request Content-Type passed by the caller is always a
     * concrete media type, so a wildcard here would be a category error.
     */
    public static function isJsonContentType(string $lowerContentType): bool
    {
        return $lowerContentType === 'application/json' || str_ends_with($lowerContentType, '+json');
    }

    /**
     * Whether `$lower` is a `<type>/*` or `*&#47;*` range (already lower-cased).
     */
    private static function isTypeRange(string $lower): bool
    {
        return str_ends_with($lower, '/*');
    }

    /**
     * Extract the type half ("application" from "application/json"), or null
     * if the input is malformed (no slash, empty type half, etc.).
     */
    private static function primaryType(string $normalized): ?string
    {
        $parts = explode('/', $normalized, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[0] === '*') {
            return null;
        }

        return $parts[0];
    }
}
