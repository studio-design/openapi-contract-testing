<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use function array_is_list;
use function array_map;
use function is_array;
use function is_string;

final class OpenApiSchemaConverter
{
    /** OAS keys to remove for both 3.0 and 3.1 */
    private const OPENAPI_COMMON_KEYS = [
        'discriminator',
        'xml',
        'externalDocs',
        'example',
        'deprecated',
    ];

    /** OAS 3.0 specific keys (not in JSON Schema Draft 07) */
    private const OPENAPI_3_0_KEYS = [
        'nullable',
        'readOnly',
        'writeOnly',
    ];

    /** Draft 2020-12 keys that don't exist in Draft 07 */
    private const DRAFT_2020_12_KEYS = [
        '$dynamicRef',
        '$dynamicAnchor',
        'contentSchema',
        'examples',
    ];

    /**
     * Convert an OpenAPI schema to a JSON Schema Draft 07 compatible schema.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    public static function convert(array $schema, OpenApiVersion $version = OpenApiVersion::V3_0): array
    {
        return self::convertRecursive($schema, $version);
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function convertRecursive(array $schema, OpenApiVersion $version): array
    {
        if ($version === OpenApiVersion::V3_0) {
            $schema = self::handleNullable($schema);
            $schema = self::removeKeys($schema, self::OPENAPI_3_0_KEYS);
        } else {
            $schema = self::handlePrefixItems($schema);
            $schema = self::removeKeys($schema, self::DRAFT_2020_12_KEYS);
        }

        $schema = self::removeKeys($schema, self::OPENAPI_COMMON_KEYS);

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $key => $property) {
                if (is_array($property)) {
                    $schema['properties'][$key] = self::convertRecursive($property, $version);
                }
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            // items can be an array (tuple from prefixItems conversion) or an object schema
            if (array_is_list($schema['items'])) {
                $schema['items'] = array_map(
                    static fn(mixed $item): mixed => is_array($item) ? self::convertRecursive($item, $version) : $item,
                    $schema['items'],
                );
            } else {
                $schema['items'] = self::convertRecursive($schema['items'], $version);
            }
        }

        foreach (['allOf', 'oneOf', 'anyOf'] as $combiner) {
            if (isset($schema[$combiner]) && is_array($schema[$combiner])) {
                $schema[$combiner] = array_map(
                    static fn(mixed $item): mixed => is_array($item) ? self::convertRecursive($item, $version) : $item,
                    $schema[$combiner],
                );
            }
        }

        if (isset($schema['additionalProperties']) && is_array($schema['additionalProperties'])) {
            $schema['additionalProperties'] = self::convertRecursive($schema['additionalProperties'], $version);
        }

        if (isset($schema['not']) && is_array($schema['not'])) {
            $schema['not'] = self::convertRecursive($schema['not'], $version);
        }

        return $schema;
    }

    /**
     * Convert OpenAPI 3.0 nullable to JSON Schema compatible type.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function handleNullable(array $schema): array
    {
        if (!isset($schema['nullable']) || $schema['nullable'] !== true) {
            return $schema;
        }

        unset($schema['nullable']);

        if (isset($schema['type']) && is_string($schema['type'])) {
            $schema['type'] = [$schema['type'], 'null'];

            return $schema;
        }

        foreach (['oneOf', 'anyOf'] as $combiner) {
            if (isset($schema[$combiner]) && is_array($schema[$combiner])) {
                $schema[$combiner][] = ['type' => 'null'];

                return $schema;
            }
        }

        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            $allOf = $schema['allOf'];
            unset($schema['allOf']);
            $schema['oneOf'] = [
                ['allOf' => $allOf],
                ['type' => 'null'],
            ];

            return $schema;
        }

        return $schema;
    }

    /**
     * Convert Draft 2020-12 prefixItems to Draft 07 items array (tuple validation).
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function handlePrefixItems(array $schema): array
    {
        if (isset($schema['prefixItems']) && is_array($schema['prefixItems'])) {
            $schema['items'] = $schema['prefixItems'];
            unset($schema['prefixItems']);
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     * @param string[] $keys
     *
     * @return array<string, mixed>
     */
    private static function removeKeys(array $schema, array $keys): array
    {
        foreach ($keys as $key) {
            unset($schema[$key]);
        }

        return $schema;
    }
}
