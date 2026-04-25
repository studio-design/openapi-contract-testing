<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Support;

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
     * Matches "application/json" exactly and any type with a "+json" structured
     * syntax suffix (RFC 6838), such as "application/problem+json" and
     * "application/vnd.api+json". Matching is case-insensitive.
     *
     * @param array<string, mixed> $content
     */
    public static function findJsonContentType(array $content): ?string
    {
        foreach ($content as $contentType => $_mediaType) {
            $lower = strtolower($contentType);

            if (self::isJsonContentType($lower)) {
                return $contentType;
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
     * @param array<string, mixed> $content
     */
    public static function findContentTypeKey(string $normalizedContentType, array $content): ?string
    {
        foreach ($content as $specContentType => $_mediaType) {
            if (strtolower($specContentType) === $normalizedContentType) {
                return $specContentType;
            }
        }

        return null;
    }

    /**
     * True for "application/json" or any "+json" structured syntax suffix (RFC 6838).
     * Expects a lower-cased media type without parameters.
     */
    public static function isJsonContentType(string $lowerContentType): bool
    {
        return $lowerContentType === 'application/json' || str_ends_with($lowerContentType, '+json');
    }
}
