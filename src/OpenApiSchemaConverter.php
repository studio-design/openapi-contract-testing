<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use function array_is_list;
use function in_array;
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
     * `$context` drives asymmetric handling of `readOnly` / `writeOnly`:
     * in `Request` context, `readOnly` properties are turned into forbidden
     * subschemas (boolean `false`) and stripped from `required`; in `Response`
     * context the same happens for `writeOnly`. See `SchemaContext` for the
     * motivating OpenAPI semantics.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    public static function convert(
        array $schema,
        OpenApiVersion $version = OpenApiVersion::V3_0,
        SchemaContext $context = SchemaContext::Response,
    ): array {
        self::convertInPlace($schema, $version, $context);

        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function convertInPlace(array &$schema, OpenApiVersion $version, SchemaContext $context): void
    {
        if ($version === OpenApiVersion::V3_0) {
            self::handleNullable($schema);
        } else {
            self::handlePrefixItems($schema);
            self::removeKeys($schema, self::DRAFT_2020_12_KEYS);
        }

        self::removeKeys($schema, self::OPENAPI_COMMON_KEYS);

        // Enforce readOnly/writeOnly on this object's own `properties` before
        // recursing so a forbidden property is replaced wholesale (no need to
        // descend into a schema that's been turned into boolean `false`), and
        // before the 3.0 key scrub so `isForbidden()` can still see the marker.
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            self::enforceContextOnProperties($schema, $context);
        }

        if ($version === OpenApiVersion::V3_0) {
            self::removeKeys($schema, self::OPENAPI_3_0_KEYS);
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as &$property) {
                if (is_array($property)) {
                    self::convertInPlace($property, $version, $context);
                }
            }
            unset($property);
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            if (array_is_list($schema['items'])) {
                foreach ($schema['items'] as &$item) {
                    if (is_array($item)) {
                        self::convertInPlace($item, $version, $context);
                    }
                }
                unset($item);
            } else {
                self::convertInPlace($schema['items'], $version, $context);
            }
        }

        foreach (['allOf', 'oneOf', 'anyOf'] as $combiner) {
            if (isset($schema[$combiner]) && is_array($schema[$combiner])) {
                foreach ($schema[$combiner] as &$item) {
                    if (is_array($item)) {
                        self::convertInPlace($item, $version, $context);
                    }
                }
                unset($item);
            }
        }

        if (isset($schema['additionalProperties']) && is_array($schema['additionalProperties'])) {
            self::convertInPlace($schema['additionalProperties'], $version, $context);
        }

        if (isset($schema['not']) && is_array($schema['not'])) {
            self::convertInPlace($schema['not'], $version, $context);
        }
    }

    /**
     * Replace the subschema of every context-forbidden property with boolean
     * `false` (Draft-07 canonical "this property must not appear") and prune
     * forbidden names from the parent `required` array.
     *
     * Detection only looks at the property's own top-level `readOnly` /
     * `writeOnly`; markers buried inside a property's `allOf` / `oneOf` /
     * `anyOf` children are not handled (known limitation).
     *
     * @param array<string, mixed> $schema
     */
    private static function enforceContextOnProperties(array &$schema, SchemaContext $context): void
    {
        /** @var array<string, mixed> $properties */
        $properties = $schema['properties'];
        $forbiddenNames = [];

        foreach ($properties as $name => $property) {
            if (!is_array($property)) {
                continue;
            }

            if (!self::isForbidden($property, $context)) {
                continue;
            }

            $properties[$name] = false;
            $forbiddenNames[] = $name;
        }

        if ($forbiddenNames === []) {
            return;
        }

        $schema['properties'] = $properties;

        if (!isset($schema['required']) || !is_array($schema['required'])) {
            return;
        }

        /** @var array<int, mixed> $required */
        $required = $schema['required'];
        $filtered = [];
        foreach ($required as $entry) {
            if (is_string($entry) && in_array($entry, $forbiddenNames, true)) {
                continue;
            }
            $filtered[] = $entry;
        }

        if ($filtered === []) {
            unset($schema['required']);

            return;
        }

        $schema['required'] = $filtered;
    }

    /**
     * @param array<string, mixed> $propertySchema
     */
    private static function isForbidden(array $propertySchema, SchemaContext $context): bool
    {
        return match ($context) {
            SchemaContext::Request => ($propertySchema['readOnly'] ?? null) === true,
            SchemaContext::Response => ($propertySchema['writeOnly'] ?? null) === true,
        };
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
