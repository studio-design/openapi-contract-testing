<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Fuzz;

use const E_USER_WARNING;

use InvalidArgumentException;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Fuzz\SchemaDataGenerator;

use function count;
use function json_decode;
use function json_encode;
use function preg_match;
use function restore_error_handler;
use function set_error_handler;
use function strlen;

class SchemaDataGeneratorTest extends TestCase
{
    #[Test]
    public function rejects_non_positive_count(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SchemaDataGenerator::generate(['type' => 'string'], 0);
    }

    #[Test]
    public function generates_strings(): void
    {
        $values = SchemaDataGenerator::generate(['type' => 'string'], 3, seed: 1);

        $this->assertCount(3, $values);
        foreach ($values as $value) {
            $this->assertIsString($value);
            $this->assertNotSame('', $value);
        }
    }

    #[Test]
    public function generates_integers_within_range(): void
    {
        $values = SchemaDataGenerator::generate(
            ['type' => 'integer', 'minimum' => 5, 'maximum' => 10],
            5,
            seed: 1,
        );

        foreach ($values as $value) {
            $this->assertIsInt($value);
            $this->assertGreaterThanOrEqual(5, $value);
            $this->assertLessThanOrEqual(10, $value);
        }
    }

    #[Test]
    public function generates_numbers_within_range(): void
    {
        $values = SchemaDataGenerator::generate(
            ['type' => 'number', 'minimum' => 1.5, 'maximum' => 2.5],
            5,
            seed: 1,
        );

        foreach ($values as $value) {
            $this->assertIsFloat($value);
            $this->assertGreaterThanOrEqual(1.5, $value);
            $this->assertLessThanOrEqual(2.5, $value);
        }
    }

    #[Test]
    public function generates_booleans(): void
    {
        $values = SchemaDataGenerator::generate(['type' => 'boolean'], 4, seed: 1);

        foreach ($values as $value) {
            $this->assertIsBool($value);
        }
    }

    #[Test]
    public function honors_required_keys_in_objects(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['name'],
            'properties' => [
                'name' => ['type' => 'string'],
                'tag' => ['type' => 'string'],
            ],
        ];

        $values = SchemaDataGenerator::generate($schema, 6, seed: 1);

        foreach ($values as $value) {
            $this->assertIsArray($value);
            $this->assertArrayHasKey('name', $value, 'required keys must always be emitted');
        }
    }

    #[Test]
    public function alternates_optional_keys_so_both_shapes_appear(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['name'],
            'properties' => [
                'name' => ['type' => 'string'],
                'tag' => ['type' => 'string'],
            ],
        ];

        $values = SchemaDataGenerator::generate($schema, 6, seed: 1);
        $hasOptional = false;
        $missingOptional = false;
        foreach ($values as $value) {
            $this->assertIsArray($value);
            if (isset($value['tag'])) {
                $hasOptional = true;
            } else {
                $missingOptional = true;
            }
        }

        $this->assertTrue($hasOptional, 'at least one case should include the optional key');
        $this->assertTrue($missingOptional, 'at least one case should omit the optional key');
    }

    #[Test]
    public function generates_arrays_of_items(): void
    {
        $schema = [
            'type' => 'array',
            'items' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
        ];

        $values = SchemaDataGenerator::generate($schema, 4, seed: 1);

        foreach ($values as $value) {
            $this->assertIsArray($value);
            $this->assertGreaterThanOrEqual(1, count($value));
            foreach ($value as $item) {
                $this->assertIsInt($item);
            }
        }
    }

    #[Test]
    public function picks_values_only_from_enum(): void
    {
        $allowed = ['available', 'pending', 'sold'];
        $schema = ['type' => 'string', 'enum' => $allowed];

        $values = SchemaDataGenerator::generate($schema, 9, seed: 1);

        foreach ($values as $value) {
            $this->assertContains($value, $allowed);
        }
    }

    #[Test]
    public function honors_format_email(): void
    {
        $values = SchemaDataGenerator::generate(['type' => 'string', 'format' => 'email'], 3, seed: 1);

        foreach ($values as $value) {
            $this->assertIsString($value);
            $this->assertSame(1, preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $value), "got: {$value}");
        }
    }

    #[Test]
    public function honors_format_uuid(): void
    {
        $values = SchemaDataGenerator::generate(['type' => 'string', 'format' => 'uuid'], 3, seed: 1);

        foreach ($values as $value) {
            $this->assertIsString($value);
            $this->assertSame(
                1,
                preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value),
                "got: {$value}",
            );
        }
    }

    #[Test]
    public function honors_min_and_max_length(): void
    {
        $schema = ['type' => 'string', 'minLength' => 4, 'maxLength' => 6];

        $values = SchemaDataGenerator::generate($schema, 5, seed: 1);

        foreach ($values as $value) {
            $this->assertIsString($value);
            $len = strlen($value);
            $this->assertGreaterThanOrEqual(4, $len);
            $this->assertLessThanOrEqual(6, $len);
        }
    }

    #[Test]
    public function same_seed_produces_same_output(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['name'],
            'properties' => [
                'name' => ['type' => 'string'],
                'tag' => ['type' => 'string'],
                'age' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ],
        ];

        $first = SchemaDataGenerator::generate($schema, 5, seed: 12345);
        $second = SchemaDataGenerator::generate($schema, 5, seed: 12345);

        $this->assertSame(json_encode($first), json_encode($second));
    }

    #[Test]
    public function generated_values_pass_opis_schema_validation(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['name', 'count'],
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20],
                'count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 99],
                'tags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => ['a', 'b', 'c']],
                ],
            ],
        ];

        $values = SchemaDataGenerator::generate($schema, 8, seed: 1);
        $validator = new Validator();
        $jsonSchema = json_decode((string) json_encode($schema));

        foreach ($values as $value) {
            $jsonValue = json_decode((string) json_encode($value));
            $result = $validator->validate($jsonValue, $jsonSchema);
            $this->assertTrue($result->isValid(), 'generated value should validate against its schema');
        }
    }

    #[Test]
    public function nullable_type_array_picks_non_null(): void
    {
        // Draft-2020 / OAS 3.1 normalised form after OpenApiSchemaConverter.
        $schema = ['type' => ['string', 'null']];

        $values = SchemaDataGenerator::generate($schema, 3, seed: 1);

        foreach ($values as $value) {
            $this->assertIsString($value);
        }
    }

    #[Test]
    public function untyped_object_is_inferred_from_properties(): void
    {
        $schema = [
            'properties' => [
                'name' => ['type' => 'string'],
            ],
            'required' => ['name'],
        ];

        $values = SchemaDataGenerator::generate($schema, 2, seed: 1);

        foreach ($values as $value) {
            $this->assertIsArray($value);
            $this->assertArrayHasKey('name', $value);
        }
    }

    #[Test]
    public function different_seeds_produce_different_output(): void
    {
        // Without this test, dropping `$faker->seed($seed)` would silently
        // pass — same_seed_produces_same_output would still hold because
        // `Faker\Factory::create()` is only called once per generate().
        $schema = ['type' => 'string', 'minLength' => 8, 'maxLength' => 8];

        $first = SchemaDataGenerator::generate($schema, 4, seed: 1);
        $second = SchemaDataGenerator::generate($schema, 4, seed: 999);

        $this->assertNotSame(json_encode($first), json_encode($second));
    }

    #[Test]
    public function fallback_strings_are_valid_without_faker(): void
    {
        // Drive generateOne() directly with a null faker so the deterministic
        // fallback runs in CI even though fakerphp/faker is always installed
        // in dev. Without this test, breaking the fallback path would never
        // surface until a downstream user without faker reported it.
        $schema = ['type' => 'string', 'minLength' => 4, 'maxLength' => 12];

        for ($i = 0; $i < 4; $i++) {
            $value = SchemaDataGenerator::generateOne($schema, faker: null, iteration: $i);
            $this->assertIsString($value);
            $this->assertGreaterThanOrEqual(4, strlen($value));
            $this->assertLessThanOrEqual(12, strlen($value));
        }
    }

    #[Test]
    public function fallback_integers_stay_within_range(): void
    {
        $schema = ['type' => 'integer', 'minimum' => 5, 'maximum' => 10];

        for ($i = 0; $i < 12; $i++) {
            $value = SchemaDataGenerator::generateOne($schema, faker: null, iteration: $i);
            $this->assertIsInt($value);
            $this->assertGreaterThanOrEqual(5, $value);
            $this->assertLessThanOrEqual(10, $value);
        }
    }

    #[Test]
    public function fallback_numbers_stay_within_tight_range(): void
    {
        // Regression for the original `+ ($iteration % 100) / 10.0` that
        // could return up to 9.9 above $min, blowing past tight maximums.
        $schema = ['type' => 'number', 'minimum' => 0.5, 'maximum' => 0.6];

        for ($i = 0; $i < 12; $i++) {
            $value = SchemaDataGenerator::generateOne($schema, faker: null, iteration: $i);
            $this->assertIsFloat($value);
            $this->assertGreaterThanOrEqual(0.5, $value);
            $this->assertLessThanOrEqual(0.6, $value);
        }
    }

    #[Test]
    public function maximum_only_integer_anchors_relative_to_max(): void
    {
        // Regression for the static `$min = 1` default that produced 1
        // even when the spec required `<= 0`.
        $schema = ['type' => 'integer', 'maximum' => 0];

        for ($i = 0; $i < 12; $i++) {
            $value = SchemaDataGenerator::generateOne($schema, faker: null, iteration: $i);
            $this->assertIsInt($value);
            $this->assertLessThanOrEqual(0, $value);
        }
    }

    #[Test]
    public function maximum_only_number_anchors_relative_to_max(): void
    {
        $schema = ['type' => 'number', 'maximum' => -1.0];

        for ($i = 0; $i < 12; $i++) {
            $value = SchemaDataGenerator::generateOne($schema, faker: null, iteration: $i);
            $this->assertIsFloat($value);
            $this->assertLessThanOrEqual(-1.0, $value);
        }
    }

    #[Test]
    public function faker_missing_for_format_emits_warning_once(): void
    {
        SchemaDataGenerator::resetWarningStateForTesting();
        $schema = ['type' => 'string', 'format' => 'email'];

        $captured = [];
        $previous = set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            if ($errno === E_USER_WARNING) {
                $captured[] = $errstr;

                return true;
            }

            return false;
        });

        try {
            SchemaDataGenerator::generateOne($schema, faker: null, iteration: 0);
            SchemaDataGenerator::generateOne($schema, faker: null, iteration: 1);
            SchemaDataGenerator::generateOne($schema, faker: null, iteration: 2);
        } finally {
            restore_error_handler();
            // Be polite to other tests — clear state we just exercised.
            SchemaDataGenerator::resetWarningStateForTesting();
            // Suppress unused-variable analyzer: the previous handler reference
            // is here purely so set_error_handler returns a callable we can
            // restore. unused: $previous
            unset($previous);
        }

        $this->assertCount(1, $captured, 'warning should fire exactly once per format per process');
        $this->assertStringContainsString("'email'", $captured[0]);
        $this->assertStringContainsString('fakerphp/faker', $captured[0]);
    }

    #[Test]
    public function oneof_schema_falls_through_to_string_default(): void
    {
        // Pin current MVP behaviour: composite schemas resolve to `string`
        // because resolveType() bottoms out there. If a future change adds
        // proper composition handling, this test should be updated — and the
        // failure makes that explicit instead of silently changing semantics.
        $schema = [
            'oneOf' => [
                ['type' => 'object'],
                ['type' => 'integer'],
            ],
        ];

        $value = SchemaDataGenerator::generateOne($schema, faker: null, iteration: 0);

        $this->assertIsString($value);
    }
}
