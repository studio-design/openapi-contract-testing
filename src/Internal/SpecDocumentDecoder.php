<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Internal;

use const JSON_THROW_ON_ERROR;

use JsonException;
use Studio\OpenApiContractTesting\InvalidOpenApiSpecException;
use Studio\OpenApiContractTesting\InvalidOpenApiSpecReason;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

use function get_debug_type;
use function is_array;
use function json_decode;
use function sprintf;

/**
 * Pure decoder for in-memory spec documents (JSON / YAML strings).
 * Both `ExternalRefLoader` (filesystem-based) and `HttpRefLoader`
 * (network-based) call into this once they have the raw body in hand,
 * so the parse + mapping-root checks live in one place.
 *
 * `$context` is a free-form string that surfaces in error messages so
 * the caller can pinpoint which ref / file / URL produced the failure
 * without the decoder having to know the source.
 *
 * @internal Not part of the package's public API. Do not call from user code.
 */
final class SpecDocumentDecoder
{
    private function __construct() {}

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidOpenApiSpecException on malformed JSON or non-mapping root
     */
    public static function decodeJson(string $content, string $context): array
    {
        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::MalformedJson,
                sprintf('Failed to parse JSON $ref target %s: %s', $context, $e->getMessage()),
                ref: $context,
                previous: $e,
            );
        }

        return self::ensureMappingRoot($decoded, $context);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidOpenApiSpecException on missing YAML library, parse failure, or non-mapping root
     */
    public static function decodeYaml(string $content, string $context): array
    {
        if (!YamlAvailability::isAvailable()) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::YamlLibraryMissing,
                'Loading YAML $ref targets requires symfony/yaml. '
                . 'Install it via: composer require --dev symfony/yaml',
                ref: $context,
            );
        }

        try {
            $decoded = Yaml::parse($content);
        } catch (ParseException $e) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::MalformedYaml,
                sprintf('Failed to parse YAML $ref target %s: %s', $context, $e->getMessage()),
                ref: $context,
                previous: $e,
            );
        }

        return self::ensureMappingRoot($decoded, $context);
    }

    /** @return array<string, mixed> */
    private static function ensureMappingRoot(mixed $decoded, string $context): array
    {
        if (!is_array($decoded)) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::NonMappingRoot,
                sprintf(
                    '$ref target must decode to a mapping (got %s): %s',
                    get_debug_type($decoded),
                    $context,
                ),
                ref: $context,
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
