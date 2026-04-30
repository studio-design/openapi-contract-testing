<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Fuzz;

use const E_USER_WARNING;

use Faker\Factory;
use Faker\Generator;
use InvalidArgumentException;
use Studio\OpenApiContractTesting\Spec\OpenApiSchemaConverter;

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
use function sprintf;
use function str_pad;
use function str_repeat;
use function strlen;
use function substr;
use function trigger_error;

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
     * Per-process record of formats already announced as "faker missing".
     * Keyed by format name; we only warn once per format to avoid spamming
     * a long fuzz run that touches many `email` properties.
     *
     * @var array<string, true>
     */
    private static array $warnedFakerFormats = [];

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
     * Build a faker generator when the package is installed; null otherwise.
     * The null branch is documented and exercised by tests — see the class
     * docblock for the determinism contract in either case.
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
     * Reset the per-process "already warned" record. Tests use this to
     * exercise the warning path multiple times without leaking state across
     * cases; production callers never need to.
     *
     * @internal
     */
    public static function resetWarningStateForTesting(): void
    {
        self::$warnedFakerFormats = [];
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

        if ($faker === null && $format !== null && self::isSupportedFormat($format)) {
            // Without faker, format-constrained strings (`email`, `uuid`, …)
            // degrade to the deterministic primitive fallback below — which
            // will not satisfy the format constraint and every fuzzed request
            // will fail at validation. Surface this once per process so the
            // user can install fakerphp/faker, instead of letting the test
            // appear "unstable" with no diagnostic.
            self::warnFakerMissing($format);
        }

        $minLength = isset($schema['minLength']) && is_int($schema['minLength']) && $schema['minLength'] >= 0
            ? $schema['minLength']
            : 0;
        $maxLength = isset($schema['maxLength']) && is_int($schema['maxLength']) && $schema['maxLength'] > 0
            ? $schema['maxLength']
            : 16;

        if ($faker !== null) {
            // bothify('?') yields random alpha sized to the chosen target.
            // Target is always >= 1 because $maxLength is constrained > 0
            // above and min($maxLength, 8) >= 1. clampLength still runs to
            // honor `minLength > 8` (where the target was capped at 8) and
            // to defensively pad when bothify ever returns a short result.
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
     * Mirrors the format keys that {@see self::generateStringByFormat()}
     * actually handles. Listing them here lets the faker-missing warning
     * fire only for cases where faker would have helped — exotic formats
     * (e.g. `byte`, `binary`, `password`) stay silent because the
     * deterministic fallback wasn't going to satisfy them either way.
     */
    private static function isSupportedFormat(string $format): bool
    {
        return in_array(
            $format,
            ['email', 'idn-email', 'uuid', 'date', 'date-time', 'time', 'uri', 'url', 'iri', 'hostname', 'ipv4', 'ipv6'],
            true,
        );
    }

    /**
     * Emit a one-shot warning per missing format. We dedupe by `$format` so
     * a long fuzz run with many email fields still only nags once per format
     * — matching how the rest of the library uses E_USER_WARNING for
     * spec-author advisories (see OpenApiCoverageTracker::warnMalformed()).
     */
    private static function warnFakerMissing(string $format): void
    {
        if (isset(self::$warnedFakerFormats[$format])) {
            return;
        }
        self::$warnedFakerFormats[$format] = true;

        trigger_error(
            sprintf(
                '[openapi-contract-testing] fakerphp/faker is not installed; '
                . "string format '%s' will be generated as a deterministic primitive "
                . 'and is unlikely to satisfy the spec constraint. '
                . 'Install via: composer require --dev fakerphp/faker',
                $format,
            ),
            E_USER_WARNING,
        );
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
        // Resolve bounds in three modes so a one-sided constraint never produces
        // out-of-range values: when only `maximum: 0` is set, anchoring `min`
        // to a static 1 would silently emit 1 every time. Anchor relative to
        // the supplied bound instead and only fall back to flat defaults when
        // both ends are unspecified.
        $minSet = isset($schema['minimum']) && (is_int($schema['minimum']) || is_float($schema['minimum']));
        $maxSet = isset($schema['maximum']) && (is_int($schema['maximum']) || is_float($schema['maximum']));

        if ($minSet && $maxSet) {
            $min = (int) $schema['minimum'];
            $max = (int) $schema['maximum'];
        } elseif ($minSet) {
            $min = (int) $schema['minimum'];
            $max = $min + 1000;
        } elseif ($maxSet) {
            $max = (int) $schema['maximum'];
            $min = $max - 1000;
        } else {
            $min = 1;
            $max = 1000;
        }

        if ($max < $min) {
            // Spec inversion (e.g. min=10, max=5). Honor `min` since it is the
            // tighter constraint for "this value must exist at all"; the tests
            // that pass a contradictory schema are expected to fail validation
            // downstream — we just refuse to amplify the contradiction.
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
        $minSet = isset($schema['minimum']) && (is_int($schema['minimum']) || is_float($schema['minimum']));
        $maxSet = isset($schema['maximum']) && (is_int($schema['maximum']) || is_float($schema['maximum']));

        if ($minSet && $maxSet) {
            $min = (float) $schema['minimum'];
            $max = (float) $schema['maximum'];
        } elseif ($minSet) {
            $min = (float) $schema['minimum'];
            $max = $min + 1000.0;
        } elseif ($maxSet) {
            $max = (float) $schema['maximum'];
            $min = $max - 1000.0;
        } else {
            $min = 0.0;
            $max = 1000.0;
        }

        if ($max < $min) {
            $max = $min;
        }

        if ($faker !== null) {
            // randomFloat(null, …) lets faker pick precision dynamically so
            // tight ranges (e.g. minimum=0.001 maximum=0.002) don't collapse
            // to 0.00 from a fixed two-decimal rounding.
            return $faker->randomFloat(null, $min, $max);
        }

        // Scale the iteration-driven offset to the actual span so the value
        // never escapes [min, max] — using a fixed `iter / 10` step would
        // exit the range whenever the span < 10.
        $span = $max - $min;
        if ($span <= 0.0) {
            return $min;
        }

        return $min + ($iteration % 100) / 100.0 * $span;
    }

    private static function generateBoolean(int $iteration): bool
    {
        return ($iteration % 2) === 1;
    }
}
