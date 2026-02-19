<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\OpenApiSchemaConverter;
use Studio\OpenApiContractTesting\OpenApiVersion;

class OpenApiSchemaConverterTest extends TestCase
{
    // ========================================
    // OAS 3.0 tests
    // ========================================

    #[Test]
    public function v30_nullable_type_converted_to_type_array(): void
    {
        $schema = [
            'type' => 'string',
            'nullable' => true,
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertSame(['string', 'null'], $result['type']);
        $this->assertArrayNotHasKey('nullable', $result);
    }

    #[Test]
    public function v30_nullable_one_of_adds_null_type(): void
    {
        $schema = [
            'nullable' => true,
            'oneOf' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertCount(3, $result['oneOf']);
        $this->assertSame(['type' => 'null'], $result['oneOf'][2]);
        $this->assertArrayNotHasKey('nullable', $result);
    }

    #[Test]
    public function v30_nullable_all_of_wrapped_in_one_of(): void
    {
        $schema = [
            'nullable' => true,
            'allOf' => [
                ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertArrayNotHasKey('allOf', $result);
        $this->assertArrayHasKey('oneOf', $result);
        $this->assertCount(2, $result['oneOf']);
        $this->assertArrayHasKey('allOf', $result['oneOf'][0]);
        $this->assertSame(['type' => 'null'], $result['oneOf'][1]);
        $this->assertArrayNotHasKey('nullable', $result);
    }

    #[Test]
    public function v30_nested_properties_converted_recursively(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'nullable' => true,
                ],
                'address' => [
                    'type' => 'object',
                    'nullable' => true,
                    'properties' => [
                        'city' => [
                            'type' => 'string',
                            'nullable' => true,
                        ],
                    ],
                ],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertSame(['string', 'null'], $result['properties']['name']['type']);
        $this->assertSame(['object', 'null'], $result['properties']['address']['type']);
        $this->assertSame(['string', 'null'], $result['properties']['address']['properties']['city']['type']);
    }

    #[Test]
    public function v30_items_nullable_converted(): void
    {
        $schema = [
            'type' => 'array',
            'items' => [
                'type' => 'string',
                'nullable' => true,
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertSame(['string', 'null'], $result['items']['type']);
    }

    #[Test]
    public function v30_openapi_only_keys_removed(): void
    {
        $schema = [
            'type' => 'string',
            'description' => 'a name',
            'example' => 'John',
            'deprecated' => true,
            'readOnly' => true,
            'writeOnly' => false,
            'xml' => ['name' => 'test'],
            'externalDocs' => ['url' => 'https://example.com'],
            'discriminator' => ['propertyName' => 'type'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertSame('string', $result['type']);
        $this->assertSame('a name', $result['description']);
        $this->assertArrayNotHasKey('example', $result);
        $this->assertArrayNotHasKey('deprecated', $result);
        $this->assertArrayNotHasKey('readOnly', $result);
        $this->assertArrayNotHasKey('writeOnly', $result);
        $this->assertArrayNotHasKey('xml', $result);
        $this->assertArrayNotHasKey('externalDocs', $result);
        $this->assertArrayNotHasKey('discriminator', $result);
    }

    #[Test]
    public function v30_nullable_false_not_converted(): void
    {
        $schema = [
            'type' => 'string',
            'nullable' => false,
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertSame('string', $result['type']);
        $this->assertArrayNotHasKey('nullable', $result);
    }

    #[Test]
    public function v30_default_version_when_omitted(): void
    {
        $schema = [
            'type' => 'string',
            'nullable' => true,
        ];

        $result = OpenApiSchemaConverter::convert($schema);

        $this->assertSame(['string', 'null'], $result['type']);
    }

    // ========================================
    // OAS 3.1 tests
    // ========================================

    #[Test]
    public function v31_type_array_preserved(): void
    {
        $schema = [
            'type' => ['string', 'null'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertSame(['string', 'null'], $result['type']);
    }

    #[Test]
    public function v31_prefix_items_converted_to_items(): void
    {
        $schema = [
            'type' => 'array',
            'prefixItems' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertArrayNotHasKey('prefixItems', $result);
        $this->assertArrayHasKey('items', $result);
        $this->assertCount(2, $result['items']);
        $this->assertSame(['type' => 'string'], $result['items'][0]);
        $this->assertSame(['type' => 'integer'], $result['items'][1]);
    }

    #[Test]
    public function v31_draft_2020_12_keys_removed(): void
    {
        $schema = [
            'type' => 'object',
            '$dynamicRef' => '#meta',
            '$dynamicAnchor' => 'meta',
            'contentSchema' => ['type' => 'string'],
            'examples' => [['key' => 'value']],
            'properties' => [
                'name' => ['type' => 'string'],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertArrayNotHasKey('$dynamicRef', $result);
        $this->assertArrayNotHasKey('$dynamicAnchor', $result);
        $this->assertArrayNotHasKey('contentSchema', $result);
        $this->assertArrayNotHasKey('examples', $result);
        $this->assertSame('object', $result['type']);
        $this->assertArrayHasKey('properties', $result);
    }

    #[Test]
    public function v31_common_openapi_keys_removed(): void
    {
        $schema = [
            'type' => 'string',
            'description' => 'a name',
            'example' => 'John',
            'deprecated' => true,
            'xml' => ['name' => 'test'],
            'externalDocs' => ['url' => 'https://example.com'],
            'discriminator' => ['propertyName' => 'type'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertSame('string', $result['type']);
        $this->assertSame('a name', $result['description']);
        $this->assertArrayNotHasKey('example', $result);
        $this->assertArrayNotHasKey('deprecated', $result);
        $this->assertArrayNotHasKey('xml', $result);
        $this->assertArrayNotHasKey('externalDocs', $result);
        $this->assertArrayNotHasKey('discriminator', $result);
    }

    #[Test]
    public function v31_nullable_keyword_not_processed(): void
    {
        // OAS 3.1 doesn't have "nullable" — type arrays are used instead.
        // If somehow present, it should NOT be converted to type array.
        $schema = [
            'type' => 'string',
            'nullable' => true,
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        // nullable is not in the 3.1 removal list, so it stays
        // but handleNullable is NOT called for 3.1
        $this->assertSame('string', $result['type']);
    }

    #[Test]
    public function v31_nested_prefix_items_converted_recursively(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'coordinates' => [
                    'type' => 'array',
                    'prefixItems' => [
                        ['type' => 'number'],
                        ['type' => 'number'],
                    ],
                ],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertArrayNotHasKey('prefixItems', $result['properties']['coordinates']);
        $this->assertArrayHasKey('items', $result['properties']['coordinates']);
        $this->assertCount(2, $result['properties']['coordinates']['items']);
    }

    #[Test]
    public function v31_read_only_write_only_preserved(): void
    {
        // In 3.1, readOnly/writeOnly are valid JSON Schema keywords (Draft 07 supports them)
        $schema = [
            'type' => 'string',
            'readOnly' => true,
            'writeOnly' => false,
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        // These are NOT removed in 3.1 mode (only removed in 3.0 mode)
        $this->assertTrue($result['readOnly']);
        $this->assertFalse($result['writeOnly']);
    }
}
