<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Spec;

use const E_USER_WARNING;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\OpenApiVersion;
use Studio\OpenApiContractTesting\SchemaContext;
use Studio\OpenApiContractTesting\Spec\OpenApiSchemaConverter;

use function restore_error_handler;
use function set_error_handler;

class OpenApiSchemaConverterTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset the converter's "warned-once" set. The set is process-global,
        // so without per-test reset the test ordering decides whether a
        // warning fires. Run filters or test-suite reordering would otherwise
        // produce non-deterministic outcomes for the warning tests below.
        parent::setUp();
        OpenApiSchemaConverter::resetWarningStateForTesting();
    }

    protected function tearDown(): void
    {
        OpenApiSchemaConverter::resetWarningStateForTesting();
        parent::tearDown();
    }

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

    // ========================================
    // Input immutability tests
    // ========================================

    #[Test]
    public function convert_does_not_mutate_input_schema(): void
    {
        $schema = [
            'type' => 'object',
            'nullable' => true,
            'example' => 'test',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'nullable' => true,
                    'example' => 'John',
                ],
                'tags' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'deprecated' => true,
                    ],
                ],
            ],
        ];
        $original = $schema;

        OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertSame($original, $schema);
    }

    // ========================================
    // SchemaContext: readOnly / writeOnly enforcement
    // ========================================

    #[Test]
    public function response_context_write_only_property_becomes_false_subschema(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'password' => ['type' => 'string', 'writeOnly' => true],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Response);

        $this->assertFalse($result['properties']['password']);
        $this->assertSame(['type' => 'integer'], $result['properties']['id']);
    }

    #[Test]
    public function response_context_write_only_property_removed_from_required(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'password' => ['type' => 'string', 'writeOnly' => true],
            ],
            'required' => ['id', 'name', 'password'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Response);

        $this->assertSame(['id', 'name'], $result['required']);
    }

    #[Test]
    public function response_context_required_key_dropped_when_it_becomes_empty(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'password' => ['type' => 'string', 'writeOnly' => true],
            ],
            'required' => ['password'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Response);

        $this->assertArrayNotHasKey('required', $result);
    }

    #[Test]
    public function response_context_read_only_property_is_not_forbidden(): void
    {
        // readOnly is allowed in responses — it should pass through, and in 3.0 mode
        // the keyword itself is scrubbed as an OAS-only key (existing behaviour).
        $schema = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'readOnly' => true],
            ],
            'required' => ['id'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Response);

        $this->assertSame(['type' => 'integer'], $result['properties']['id']);
        $this->assertSame(['id'], $result['required']);
    }

    #[Test]
    public function request_context_read_only_property_becomes_false_subschema(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'name' => ['type' => 'string'],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Request);

        $this->assertFalse($result['properties']['id']);
        $this->assertSame(['type' => 'string'], $result['properties']['name']);
    }

    #[Test]
    public function request_context_read_only_property_removed_from_required(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'name' => ['type' => 'string'],
            ],
            'required' => ['id', 'name'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Request);

        $this->assertSame(['name'], $result['required']);
    }

    #[Test]
    public function request_context_write_only_property_is_not_forbidden(): void
    {
        // writeOnly is allowed in requests — it should pass through.
        $schema = [
            'type' => 'object',
            'properties' => [
                'password' => ['type' => 'string', 'writeOnly' => true],
            ],
            'required' => ['password'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Request);

        $this->assertSame(['type' => 'string'], $result['properties']['password']);
        $this->assertSame(['password'], $result['required']);
    }

    #[Test]
    public function response_context_write_only_enforced_on_nested_properties(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'user' => [
                    'type' => 'object',
                    'properties' => [
                        'password' => ['type' => 'string', 'writeOnly' => true],
                        'name' => ['type' => 'string'],
                    ],
                    'required' => ['password', 'name'],
                ],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Response);

        $this->assertFalse($result['properties']['user']['properties']['password']);
        $this->assertSame(['name'], $result['properties']['user']['required']);
    }

    #[Test]
    public function v31_write_only_property_still_enforced_in_response_context(): void
    {
        // In 3.1 the keyword is Draft-07-valid and normally preserved, but Response
        // context must still reject the property from appearing in a response body.
        $schema = [
            'type' => 'object',
            'properties' => [
                'password' => ['type' => 'string', 'writeOnly' => true],
                'name' => ['type' => 'string', 'readOnly' => true],
            ],
            'required' => ['password'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1, SchemaContext::Response);

        $this->assertFalse($result['properties']['password']);
        // The non-forbidden branch keeps the Draft-07-valid keyword in 3.1.
        $this->assertTrue($result['properties']['name']['readOnly']);
        $this->assertArrayNotHasKey('required', $result);
    }

    #[Test]
    public function default_context_is_response(): void
    {
        // Default is Response so callers that don't pass a context still get
        // the response-leak guard on writeOnly properties.
        $schema = [
            'type' => 'object',
            'properties' => [
                'password' => ['type' => 'string', 'writeOnly' => true],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertFalse($result['properties']['password']);
    }

    #[Test]
    public function response_context_write_only_enforced_inside_array_items(): void
    {
        // Array-of-object is the most common list-endpoint shape. The recursion
        // into `items` must carry the context so a writeOnly property inside an
        // element is still replaced.
        $schema = [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'password' => ['type' => 'string', 'writeOnly' => true],
                ],
                'required' => ['id', 'password'],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Response);

        $this->assertFalse($result['items']['properties']['password']);
        $this->assertSame(['id'], $result['items']['required']);
    }

    #[Test]
    public function convert_does_not_mutate_input_schema_with_forbidden_properties(): void
    {
        // Immutability regression guard specifically for the enforcement path,
        // which writes to $schema['properties'] and $schema['required'].
        $schema = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'password' => ['type' => 'string', 'writeOnly' => true],
                'name' => ['type' => 'string'],
            ],
            'required' => ['id', 'password', 'name'],
        ];
        $original = $schema;

        OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Response);
        OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Request);

        $this->assertSame($original, $schema);
    }

    #[Test]
    public function marker_inside_combiner_child_is_not_enforced_known_limitation(): void
    {
        // Characterisation test pinning the documented limitation: detection
        // only looks at the property's own top-level readOnly/writeOnly, not
        // at markers buried inside allOf/oneOf/anyOf children. If this test
        // ever starts failing, the limitation has been lifted — update the
        // README and the enforceContextOnProperties docblock accordingly.
        $schema = [
            'type' => 'object',
            'properties' => [
                'password' => [
                    'allOf' => [
                        ['type' => 'string', 'writeOnly' => true],
                    ],
                ],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1, SchemaContext::Response);

        $this->assertNotFalse($result['properties']['password']);
        $this->assertArrayHasKey('allOf', $result['properties']['password']);
    }

    #[Test]
    public function convert_does_not_mutate_input_schema_v31(): void
    {
        $schema = [
            'type' => 'object',
            'prefixItems' => [
                ['type' => 'string'],
            ],
            'examples' => [['key' => 'value']],
            'properties' => [
                'data' => [
                    'type' => 'object',
                    '$dynamicRef' => '#meta',
                ],
            ],
        ];
        $original = $schema;

        OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertSame($original, $schema);
    }

    // ========================================
    // OAS 3.0 nullable + enum (regression)
    // ========================================

    #[Test]
    public function v30_nullable_with_enum_appends_null_to_enum(): void
    {
        // OAS 3.0 convention: `nullable: true` next to `enum: [...]` implies
        // null is also valid. The pre-fix converter only rewrote `type` and
        // left `enum` intact, causing opis to reject `null` against the enum.
        $schema = [
            'type' => 'string',
            'nullable' => true,
            'enum' => ['a', 'b'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertSame(['string', 'null'], $result['type']);
        $this->assertContains(null, $result['enum']);
        $this->assertCount(3, $result['enum']);
    }

    #[Test]
    public function v30_nullable_with_enum_already_containing_null_unchanged(): void
    {
        $schema = [
            'type' => 'string',
            'nullable' => true,
            'enum' => ['a', null, 'b'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        // null is already present — do not duplicate.
        $nullCount = 0;
        foreach ($result['enum'] as $value) {
            if ($value === null) {
                $nullCount++;
            }
        }
        $this->assertSame(1, $nullCount);
    }

    #[Test]
    public function v30_nullable_with_enum_and_no_type_still_appends_null(): void
    {
        // `enum` alone (no explicit `type`) is valid OAS — null still becomes
        // valid under nullable.
        $schema = [
            'nullable' => true,
            'enum' => ['a', 'b'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertContains(null, $result['enum']);
    }

    // ========================================
    // OAS 3.0 schema-level `examples` strip
    // ========================================

    #[Test]
    public function v30_examples_keyword_stripped(): void
    {
        // `examples` (the JSON Schema array form) is a Draft 2020-12 keyword
        // not understood by Draft 07. We strip it in 3.1; should also strip
        // in 3.0 for consistency since `example` (singular) is already removed.
        $schema = [
            'type' => 'string',
            'examples' => ['foo', 'bar'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertArrayNotHasKey('examples', $result);
    }

    // ========================================
    // OAS 3.1 const lowered to enum (Draft 07)
    // ========================================

    #[Test]
    public function v31_const_lowered_to_single_value_enum(): void
    {
        // Draft 07 doesn't support `const`. Lower to `enum: [value]` so opis
        // actually enforces the value rather than silently passing.
        $schema = [
            'type' => 'string',
            'const' => 'fixed',
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertArrayNotHasKey('const', $result);
        $this->assertSame(['fixed'], $result['enum']);
    }

    #[Test]
    public function v31_const_null_value_lowered_to_enum(): void
    {
        $schema = [
            'const' => null,
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertArrayNotHasKey('const', $result);
        $this->assertSame([null], $result['enum']);
    }

    #[Test]
    public function v31_const_does_not_overwrite_existing_enum(): void
    {
        // If both keys are present the spec is malformed; preserve `enum` and
        // drop `const` (the conservative choice — `enum` is the wider constraint).
        $schema = [
            'enum' => ['a', 'b'],
            'const' => 'a',
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertSame(['a', 'b'], $result['enum']);
        $this->assertArrayNotHasKey('const', $result);
    }

    // ========================================
    // OAS 3.1 unsupported keywords (loud warning, not silent pass)
    // ========================================

    #[Test]
    public function v31_pattern_properties_emits_warning(): void
    {
        // Draft 07 + opis ignore `patternProperties`, so a strict spec would
        // silently pass any property keys. Surface this loudly via E_USER_WARNING.
        $schema = [
            'type' => 'object',
            'patternProperties' => [
                '^x-' => ['type' => 'string'],
            ],
        ];

        $captured = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            if ($errno === E_USER_WARNING) {
                $captured = $errstr;

                return true;
            }

            return false;
        });

        try {
            OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);
        } finally {
            restore_error_handler();
        }

        $this->assertNotNull($captured);
        $this->assertStringContainsString('patternProperties', (string) $captured);
    }

    #[Test]
    public function v31_unevaluated_properties_emits_warning(): void
    {
        $schema = [
            'type' => 'object',
            'unevaluatedProperties' => false,
        ];

        $captured = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            if ($errno === E_USER_WARNING) {
                $captured = $errstr;

                return true;
            }

            return false;
        });

        try {
            OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);
        } finally {
            restore_error_handler();
        }

        $this->assertNotNull($captured);
        $this->assertStringContainsString('unevaluatedProperties', (string) $captured);
    }

    #[Test]
    public function repeated_calls_with_same_keyword_warn_only_once(): void
    {
        // Avoid log spam: warn once per process for a given keyword, not per
        // call. The schema converter keeps an internal seen-set.
        // (setUp() already reset the state.)
        $schema = [
            'type' => 'object',
            'patternProperties' => ['^x-' => ['type' => 'string']],
        ];

        $count = 0;
        set_error_handler(static function (int $errno) use (&$count): bool {
            if ($errno === E_USER_WARNING) {
                $count++;

                return true;
            }

            return false;
        });

        try {
            OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);
            OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);
            OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(1, $count);
    }

    #[Test]
    public function multiple_unsupported_keywords_on_same_schema_warn_independently(): void
    {
        // The dedup is per-keyword, not per-call. A schema declaring three
        // unsupported keywords at once must surface three warnings, not one.
        // Otherwise a future loop-with-break refactor would silently drop the
        // 2nd/3rd warnings.
        $schema = [
            'type' => 'object',
            'patternProperties' => ['^x-' => ['type' => 'string']],
            'unevaluatedProperties' => false,
            'contentMediaType' => 'application/json',
        ];

        $captured = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            if ($errno === E_USER_WARNING) {
                $captured[] = $errstr;

                return true;
            }

            return false;
        });

        try {
            OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);
        } finally {
            restore_error_handler();
        }

        $this->assertCount(3, $captured);
    }

    #[Test]
    public function pattern_properties_emits_warning_for_oas_3_0_too(): void
    {
        // patternProperties has been a JSON Schema keyword since draft-3 and
        // appears in OAS 3.0 specs in the wild. The fix must run for both
        // versions — a 3.0-only silent pass is just as bad as the 3.1 case.
        $schema = [
            'type' => 'object',
            'patternProperties' => ['^x-' => ['type' => 'string']],
        ];

        $captured = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            if ($errno === E_USER_WARNING) {
                $captured = $errstr;

                return true;
            }

            return false;
        });

        try {
            OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);
        } finally {
            restore_error_handler();
        }

        $this->assertNotNull($captured);
        $this->assertStringContainsString('patternProperties', (string) $captured);
    }

    // ========================================
    // OAS 3.1 examples-still-stripped (regression guard)
    // ========================================

    #[Test]
    public function v31_examples_keyword_still_stripped(): void
    {
        // `examples` was moved from DRAFT_2020_12_KEYS into OPENAPI_COMMON_KEYS.
        // The 3.1 path therefore reaches it through a different code branch
        // than before. Pin the behaviour explicitly so a future refactor
        // doesn't accidentally remove it from OPENAPI_COMMON_KEYS thinking
        // it is a 3.1-only concern (and breaks 3.0 too).
        $schema = [
            'type' => 'string',
            'examples' => ['foo', 'bar'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertArrayNotHasKey('examples', $result);
    }

    // ========================================
    // Recursion: handlers must apply at every depth
    // ========================================

    #[Test]
    public function v30_nullable_with_enum_appended_recursively_in_nested_property(): void
    {
        // Regression guard: handleNullable() runs on the outer schema only at
        // the outermost convert(), but convertInPlace recurses through
        // properties/items, so each subschema's nullable+enum should receive
        // the same treatment at its own level.
        $schema = [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'nullable' => true,
                    'enum' => ['active', 'inactive'],
                ],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertContains(null, $result['properties']['status']['enum']);
    }

    #[Test]
    public function v30_nullable_with_enum_appended_recursively_in_array_items(): void
    {
        $schema = [
            'type' => 'array',
            'items' => [
                'type' => 'string',
                'nullable' => true,
                'enum' => ['a', 'b'],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertContains(null, $result['items']['enum']);
    }

    #[Test]
    public function v31_const_lowered_recursively_in_nested_property(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'kind' => [
                    'const' => 'pet',
                ],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertArrayNotHasKey('const', $result['properties']['kind']);
        $this->assertSame(['pet'], $result['properties']['kind']['enum']);
    }

    #[Test]
    public function v31_const_lowered_recursively_in_array_items(): void
    {
        $schema = [
            'type' => 'array',
            'items' => [
                'const' => 'fixed',
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertArrayNotHasKey('const', $result['items']);
        $this->assertSame(['fixed'], $result['items']['enum']);
    }

    // ========================================
    // Falsy / collection const values
    // ========================================

    #[Test]
    public function v31_const_false_lowered_to_enum(): void
    {
        // `array_key_exists` correctly detects const: false; isset() would not.
        // Pin the implementation choice with an explicit test.
        $result = OpenApiSchemaConverter::convert(['const' => false], OpenApiVersion::V3_1);

        $this->assertArrayNotHasKey('const', $result);
        $this->assertSame([false], $result['enum']);
    }

    #[Test]
    public function v31_const_zero_lowered_to_enum(): void
    {
        $result = OpenApiSchemaConverter::convert(['const' => 0], OpenApiVersion::V3_1);

        $this->assertSame([0], $result['enum']);
    }

    #[Test]
    public function v31_const_empty_string_lowered_to_enum(): void
    {
        $result = OpenApiSchemaConverter::convert(['const' => ''], OpenApiVersion::V3_1);

        $this->assertSame([''], $result['enum']);
    }

    #[Test]
    public function v31_const_empty_array_lowered_to_enum(): void
    {
        $result = OpenApiSchemaConverter::convert(['const' => []], OpenApiVersion::V3_1);

        $this->assertSame([[]], $result['enum']);
    }
}
