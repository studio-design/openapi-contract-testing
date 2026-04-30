<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Fuzz;

use InvalidArgumentException;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Fuzz\SchemaDataGenerator;

use function count;
use function json_decode;
use function json_encode;
use function preg_match;
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
}
