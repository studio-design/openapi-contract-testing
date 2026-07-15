<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use Opis\JsonSchema\Validator;
use stdClass;

use function in_array;
use function is_array;
use function json_decode;
use function json_encode;
use function sprintf;

/** @internal */
final class SchemaValueValidator
{
    /** @var list<string> */
    private const SCHEMA_MAP_KEYWORDS = [
        'properties',
        'patternProperties',
        'dependentSchemas',
        '$defs',
        'definitions',
    ];

    /** @var list<string> */
    private const SCHEMA_LIST_KEYWORDS = [
        'allOf',
        'anyOf',
        'oneOf',
        'prefixItems',
    ];

    /** @var list<string> */
    private const SCHEMA_VALUE_KEYWORDS = [
        'items',
        'additionalItems',
        'additionalProperties',
        'contains',
        'not',
        'if',
        'then',
        'else',
        'propertyNames',
        'contentSchema',
        'unevaluatedProperties',
        'unevaluatedItems',
    ];

    /** @param array<string, mixed> $schema */
    public static function isValid(mixed $value, array $schema): bool
    {
        $validator = new Validator();
        $instance = json_decode((string) json_encode($value));
        $jsonSchema = json_decode((string) json_encode(self::schemaObject($schema)));

        return $validator->validate($instance, $jsonSchema)->isValid();
    }

    /** @param array<string, mixed> $schema */
    public static function assertValid(mixed $value, array $schema, int $iteration): void
    {
        if (self::isValid($value, $schema)) {
            return;
        }

        throw new FuzzGenerationException(sprintf(
            'Internal fuzz generator defect: valid case %d does not satisfy its converted JSON Schema.',
            $iteration,
        ), $iteration);
    }

    /**
     * PHP uses `[]` for both an empty mapping and an empty list. Restore JSON
     * objects at schema-valued keyword positions before handing the converted
     * schema to opis; opaque values and list-valued keywords remain untouched.
     *
     * @param array<string, mixed> $schema
     */
    private static function schemaObject(array $schema): stdClass
    {
        $object = new stdClass();

        foreach ($schema as $key => $value) {
            if (in_array($key, self::SCHEMA_MAP_KEYWORDS, true) && is_array($value)) {
                $object->{$key} = self::schemaMap($value);

                continue;
            }

            if (in_array($key, self::SCHEMA_LIST_KEYWORDS, true) && is_array($value)) {
                $items = [];
                foreach ($value as $item) {
                    $items[] = is_array($item) ? self::schemaObject($item) : $item;
                }
                $object->{$key} = $items;

                continue;
            }

            if (in_array($key, self::SCHEMA_VALUE_KEYWORDS, true) && is_array($value)) {
                $object->{$key} = self::schemaObject($value);

                continue;
            }

            $object->{$key} = $value;
        }

        return $object;
    }

    /**
     * @param array<array-key, mixed> $schemas
     */
    private static function schemaMap(array $schemas): stdClass
    {
        $object = new stdClass();

        foreach ($schemas as $name => $schema) {
            $object->{(string) $name} = is_array($schema) ? self::schemaObject($schema) : $schema;
        }

        return $object;
    }
}
