<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use function array_is_list;
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
        self::convertInPlace($schema, $version);

        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function convertInPlace(array &$schema, OpenApiVersion $version): void
    {
        if ($version === OpenApiVersion::V3_0) {
            self::handleNullable($schema);
            self::removeKeys($schema, self::OPENAPI_3_0_KEYS);
        } else {
            self::handlePrefixItems($schema);
            self::removeKeys($schema, self::DRAFT_2020_12_KEYS);
        }

        self::removeKeys($schema, self::OPENAPI_COMMON_KEYS);

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as &$property) {
                if (is_array($property)) {
                    self::convertInPlace($property, $version);
                }
            }
            unset($property);
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            if (array_is_list($schema['items'])) {
                foreach ($schema['items'] as &$item) {
                    if (is_array($item)) {
                        self::convertInPlace($item, $version);
                    }
                }
                unset($item);
            } else {
                self::convertInPlace($schema['items'], $version);
            }
        }

        foreach (['allOf', 'oneOf', 'anyOf'] as $combiner) {
            if (isset($schema[$combiner]) && is_array($schema[$combiner])) {
                foreach ($schema[$combiner] as &$item) {
                    if (is_array($item)) {
                        self::convertInPlace($item, $version);
                    }
                }
                unset($item);
            }
        }

        if (isset($schema['additionalProperties']) && is_array($schema['additionalProperties'])) {
            self::convertInPlace($schema['additionalProperties'], $version);
        }

        if (isset($schema['not']) && is_array($schema['not'])) {
            self::convertInPlace($schema['not'], $version);
        }
    }

    /**
     * Convert OpenAPI 3.0 nullable to JSON Schema compatible type.
     *
     * @param array<string, mixed> $schema
     */
    private static function handleNullable(array &$schema): void
    {
        if (!isset($schema['nullable']) || $schema['nullable'] !== true) {
            return;
        }

        unset($schema['nullable']);

        if (isset($schema['type']) && is_string($schema['type'])) {
            $schema['type'] = [$schema['type'], 'null'];

            return;
        }

        foreach (['oneOf', 'anyOf'] as $combiner) {
            if (isset($schema[$combiner]) && is_array($schema[$combiner])) {
                $schema[$combiner][] = ['type' => 'null'];

                return;
            }
        }

        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            $allOf = $schema['allOf'];
            unset($schema['allOf']);
            $schema['oneOf'] = [
                ['allOf' => $allOf],
                ['type' => 'null'],
            ];
        }
    }

    /**
     * Convert Draft 2020-12 prefixItems to Draft 07 items array (tuple validation).
     *
     * @param array<string, mixed> $schema
     */
    private static function handlePrefixItems(array &$schema): void
    {
        if (isset($schema['prefixItems']) && is_array($schema['prefixItems'])) {
            $schema['items'] = $schema['prefixItems'];
            unset($schema['prefixItems']);
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param string[] $keys
     */
    private static function removeKeys(array &$schema, array $keys): void
    {
        foreach ($keys as $key) {
            unset($schema[$key]);
        }
    }
}
