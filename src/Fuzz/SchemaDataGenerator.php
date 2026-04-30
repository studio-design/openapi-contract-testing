<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Fuzz;

use Faker\Factory;
use Faker\Generator;
use InvalidArgumentException;
use Studio\OpenApiContractTesting\OpenApiSchemaConverter;

use function array_values;
use function class_exists;
use function count;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function max;
use function min;
use function str_pad;
use function str_repeat;
use function strlen;
use function substr;

/**
 * Generate happy-path values that conform to a JSON Schema (Draft 07).
 *
 * Inputs are expected to be already-converted via {@see OpenApiSchemaConverter}
 * — i.e. OAS-only keys (`nullable`, `discriminator`, etc.) have been stripped
 * and OAS 3.1 type-arrays normalised. The generator is deliberately minimal:
 *
 * Supported keywords: `type` (string|integer|number|boolean|object|array|null),
 * `enum`, `format` (email|uuid|date|date-time|uri|url), `minLength`/`maxLength`,
 * `minimum`/`maximum`, `required`, `properties`, `items`.
 *
 * Out of scope (MVP): `oneOf`/`anyOf`/`allOf` composition, regex `pattern`,
 * `additionalProperties: <schema>`, `minItems`/`maxItems`, `multipleOf`.
 *
 * Determinism: when a `$seed` is supplied AND `fakerphp/faker` is installed,
 * faker is seeded so the same `(schema, count, seed)` triple produces the same
 * output across runs. Without faker, the generator falls back to deterministic
 * values keyed off the per-case iteration index — repeated calls still produce
 * identical output for the same `count`.
 */
final class SchemaDataGenerator
{
    /**
     * @param array<string, mixed> $schema
     *
     * @return list<mixed>
     */
    public static function generate(array $schema, int $count, ?int $seed = null): array
    {
        if ($count < 1) {
            throw new InvalidArgumentException(
                'SchemaDataGenerator::generate() requires count >= 1, got ' . $count . '.',
            );
        }

        $faker = self::createFaker($seed);
        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $results[] = self::generateOne($schema, $faker, $i);
        }

        return $results;
    }

    /**
     * Single-value generation entry — exposed so callers like
     * {@see OpenApiEndpointExplorer} can share a faker instance across
     * multiple schemas (body + parameters) within a single case.
     *
     * @param array<string, mixed> $schema
     */
    public static function generateOne(array $schema, ?Generator $faker, int $iteration): mixed
    {
        if (isset($schema['enum']) && is_array($schema['enum']) && $schema['enum'] !== []) {
            $values = array_values($schema['enum']);

            return $values[$iteration % count($values)];
        }

        $type = self::resolveType($schema);

        return match ($type) {
            'object' => self::generateObject($schema, $faker, $iteration),
            'array' => self::generateArray($schema, $faker, $iteration),
            'string' => self::generateString($schema, $faker, $iteration),
            'integer' => self::generateInteger($schema, $faker, $iteration),
            'number' => self::generateNumber($schema, $faker, $iteration),
            'boolean' => self::generateBoolean($iteration),
            'null' => null,
            default => null,
        };
    }

    /**
     * Build a faker generator when the package is installed. Returning null
     * is the documented fallback path — callers must handle either branch.
     */
    public static function createFaker(?int $seed): ?Generator
    {
        if (!class_exists(Factory::class)) {
            return null;
        }

        $faker = Factory::create();
        if ($seed !== null) {
            $faker->seed($seed);
        }

        return $faker;
    }

    /**
     * Resolve the effective type of a schema. Type may be a string, a
     * Draft-2020 array (`["string", "null"]` for nullable), or absent — in
     * which case we infer from `properties`/`items` and finally default to
     * `string` so a permissive untyped schema still produces a value.
     *
     * @param array<string, mixed> $schema
     */
    private static function resolveType(array $schema): string
    {
        $type = $schema['type'] ?? null;
        if (is_string($type)) {
            return $type;
        }

        if (is_array($type) && $type !== []) {
            foreach ($type as $candidate) {
                if (is_string($candidate) && $candidate !== 'null') {
                    return $candidate;
                }
            }

            return 'null';
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            return 'object';
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            return 'array';
        }

        return 'string';
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function generateObject(array $schema, ?Generator $faker, int $iteration): array
    {
        $properties = $schema['properties'] ?? [];
        if (!is_array($properties)) {
            return [];
        }

        $required = [];
        if (isset($schema['required']) && is_array($schema['required'])) {
            foreach ($schema['required'] as $name) {
                if (is_string($name)) {
                    $required[] = $name;
                }
            }
        }

        $result = [];
        foreach ($properties as $name => $propSchema) {
            if (!is_string($name) || !is_array($propSchema)) {
                continue;
            }

            $isRequired = in_array($name, $required, true);
            // Optional properties alternate inclusion across cases so the
            // suite exercises both "required-only" and "required+optional"
            // shapes — mirrors Schemathesis' explore-omit toggle on a small
            // budget. Required keys are always emitted.
            if (!$isRequired && ($iteration % 2) === 0) {
                continue;
            }

            $result[$name] = self::generateOne($propSchema, $faker, $iteration);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<mixed>
     */
    private static function generateArray(array $schema, ?Generator $faker, int $iteration): array
    {
        $items = $schema['items'] ?? null;
        if (!is_array($items)) {
            return [];
        }

        $size = ($iteration % 2) + 1;
        $result = [];
        for ($i = 0; $i < $size; $i++) {
            $result[] = self::generateOne($items, $faker, $iteration + $i);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function generateString(array $schema, ?Generator $faker, int $iteration): string
    {
        $format = isset($schema['format']) && is_string($schema['format']) ? $schema['format'] : null;
        if ($faker !== null && $format !== null) {
            $formatted = self::generateStringByFormat($faker, $format);
            if ($formatted !== null) {
                return self::clampLength($formatted, $schema);
            }
        }

        $minLength = isset($schema['minLength']) && is_int($schema['minLength']) && $schema['minLength'] >= 0
            ? $schema['minLength']
            : 0;
        $maxLength = isset($schema['maxLength']) && is_int($schema['maxLength']) && $schema['maxLength'] > 0
            ? $schema['maxLength']
            : 16;

        if ($faker !== null) {
            // bothify('?') yields random alpha; fixed length keeps values
            // inside [minLength, maxLength] without an extra clamp pass.
            // The target is always >= 1 because $maxLength is constrained > 0
            // above and min($maxLength, 8) >= 1.
            $target = max($minLength, min($maxLength, 8));
            $generated = $faker->bothify(str_repeat('?', $target));

            return self::clampLength($generated, $schema);
        }

        $base = 'string-' . $iteration;

        return self::clampLength($base, $schema);
    }

    private static function generateStringByFormat(Generator $faker, string $format): ?string
    {
        return match ($format) {
            'email', 'idn-email' => $faker->safeEmail(),
            'uuid' => $faker->uuid(),
            'date' => $faker->date(),
            'date-time' => $faker->iso8601(),
            'time' => $faker->time(),
            'uri', 'url', 'iri' => $faker->url(),
            'hostname' => $faker->domainName(),
            'ipv4' => $faker->ipv4(),
            'ipv6' => $faker->ipv6(),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function clampLength(string $value, array $schema): string
    {
        $minLength = isset($schema['minLength']) && is_int($schema['minLength']) && $schema['minLength'] >= 0
            ? $schema['minLength']
            : 0;
        $maxLength = isset($schema['maxLength']) && is_int($schema['maxLength']) && $schema['maxLength'] > 0
            ? $schema['maxLength']
            : null;

        if ($maxLength !== null && strlen($value) > $maxLength) {
            $value = substr($value, 0, $maxLength);
        }
        if (strlen($value) < $minLength) {
            $value = str_pad($value, $minLength, 'x');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function generateInteger(array $schema, ?Generator $faker, int $iteration): int
    {
        $min = isset($schema['minimum']) && (is_int($schema['minimum']) || is_float($schema['minimum']))
            ? (int) $schema['minimum']
            : 1;
        $max = isset($schema['maximum']) && (is_int($schema['maximum']) || is_float($schema['maximum']))
            ? (int) $schema['maximum']
            : 1000;

        if ($max < $min) {
            $max = $min;
        }

        if ($faker !== null) {
            return $faker->numberBetween($min, $max);
        }

        $span = $max - $min + 1;

        return $min + ($iteration % max(1, $span));
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function generateNumber(array $schema, ?Generator $faker, int $iteration): float
    {
        $min = isset($schema['minimum']) && (is_int($schema['minimum']) || is_float($schema['minimum']))
            ? (float) $schema['minimum']
            : 0.0;
        $max = isset($schema['maximum']) && (is_int($schema['maximum']) || is_float($schema['maximum']))
            ? (float) $schema['maximum']
            : 1000.0;

        if ($max < $min) {
            $max = $min;
        }

        if ($faker !== null) {
            return $faker->randomFloat(2, $min, $max);
        }

        return $min + ($iteration % 100) / 10.0;
    }

    private static function generateBoolean(int $iteration): bool
    {
        return ($iteration % 2) === 1;
    }
}
