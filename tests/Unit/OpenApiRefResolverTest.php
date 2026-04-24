<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Studio\OpenApiContractTesting\OpenApiRefResolver;

class OpenApiRefResolverTest extends TestCase
{
    #[Test]
    public function resolves_simple_internal_ref(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'Pet' => ['type' => 'object', 'required' => ['id']],
                ],
            ],
            'paths' => [
                '/pets' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/Pet'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $schema = $resolved['paths']['/pets']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertSame(['type' => 'object', 'required' => ['id']], $schema);
    }

    #[Test]
    public function resolves_nested_refs(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'A' => ['$ref' => '#/components/schemas/B'],
                    'B' => ['$ref' => '#/components/schemas/C'],
                    'C' => ['type' => 'string'],
                ],
            ],
            'paths' => [
                '/x' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/A'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $schema = $resolved['paths']['/x']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertSame(['type' => 'string'], $schema);
    }

    #[Test]
    public function resolves_ref_inside_resolved_target(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'Pet' => [
                        'type' => 'object',
                        'properties' => [
                            'category' => ['$ref' => '#/components/schemas/Category'],
                        ],
                    ],
                    'Category' => ['type' => 'string'],
                ],
            ],
            'paths' => [
                '/pets' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/Pet'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $schema = $resolved['paths']['/pets']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertSame(
            [
                'type' => 'object',
                'properties' => [
                    'category' => ['type' => 'string'],
                ],
            ],
            $schema,
        );
    }

    #[Test]
    public function resolves_ref_with_json_pointer_escapes(): void
    {
        // Segment containing '/' is encoded as '~1'; '~' is encoded as '~0'.
        // Order when decoding matters: '~1' first, then '~0'.
        $spec = [
            'components' => [
                'schemas' => [
                    'weird/name~with~tildes' => ['type' => 'string'],
                ],
            ],
            'paths' => [
                '/x' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            '$ref' => '#/components/schemas/weird~1name~0with~0tildes',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $schema = $resolved['paths']['/x']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertSame(['type' => 'string'], $schema);
    }

    #[Test]
    public function resolves_ref_with_url_encoded_segment(): void
    {
        // OpenAPI path keys with '/' are legal; inside a JSON Pointer they get '~1',
        // but consumers sometimes URL-encode too. Accept both.
        $spec = [
            'paths' => [
                '/pets' => ['get' => ['operationId' => 'listPets']],
            ],
            'x-alias' => ['$ref' => '#/paths/~1pets'],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $this->assertSame(['get' => ['operationId' => 'listPets']], $resolved['x-alias']);
    }

    #[Test]
    public function ref_with_siblings_drops_siblings(): void
    {
        // Per OAS 3.0, sibling keys of $ref are ignored. The resolver should
        // replace the node entirely with the target.
        $spec = [
            'components' => [
                'schemas' => [
                    'Pet' => ['type' => 'object', 'required' => ['id']],
                ],
            ],
            'x-holder' => [
                '$ref' => '#/components/schemas/Pet',
                'description' => 'ignored per OAS 3.0',
                'example' => 'ignored too',
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $this->assertSame(['type' => 'object', 'required' => ['id']], $resolved['x-holder']);
    }

    #[Test]
    public function leaves_spec_without_refs_unchanged(): void
    {
        $spec = [
            'openapi' => '3.0.3',
            'info' => ['title' => 'no refs', 'version' => '1.0'],
            'paths' => [
                '/pets' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'description' => 'ok',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['type' => 'object'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertSame($spec, OpenApiRefResolver::resolve($spec));
    }

    #[Test]
    public function resolves_ref_targeting_array_index(): void
    {
        // RFC 6901 allows numeric segments to address list elements. `$ref`s
        // pointing to `parameters[0]` are common when aliasing operation-level
        // definitions; the walker must descend through numeric keys correctly.
        $spec = [
            'paths' => [
                '/pets' => [
                    'parameters' => [
                        [
                            'name' => 'petId',
                            'in' => 'path',
                            'required' => true,
                            'schema' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
            'x-alias' => ['$ref' => '#/paths/~1pets/parameters/0'],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $this->assertSame('petId', $resolved['x-alias']['name']);
        $this->assertSame('path', $resolved['x-alias']['in']);
    }

    #[Test]
    public function resolves_ref_to_empty_object_target(): void
    {
        // An empty-object schema is legal (matches anything). The walker's
        // foreach must handle the zero-child case cleanly.
        $spec = [
            'components' => [
                'schemas' => [
                    'AnyShape' => [],
                ],
            ],
            'x-alias' => ['$ref' => '#/components/schemas/AnyShape'],
        ];

        $this->assertSame([], OpenApiRefResolver::resolve($spec)['x-alias']);
    }

    #[Test]
    public function resolves_ref_with_utf8_segment_url_encoded(): void
    {
        // Non-ASCII schema names are valid JSON. URL-aware tooling often
        // percent-encodes them in refs; rawurldecode() must run before the
        // JSON Pointer escape unwinding so both forms resolve.
        $spec = [
            'components' => [
                'schemas' => [
                    'ペット' => ['type' => 'string'],
                ],
            ],
            'x-alias' => ['$ref' => '#/components/schemas/%E3%83%9A%E3%83%83%E3%83%88'],
        ];

        $this->assertSame(
            ['type' => 'string'],
            OpenApiRefResolver::resolve($spec)['x-alias'],
        );
    }

    #[Test]
    public function resolves_ref_inside_all_of_composition(): void
    {
        // allOf/oneOf/anyOf arrays are the most common place `$ref` appears
        // in practice. A regression in list-index walking would surface here
        // first.
        $spec = [
            'components' => [
                'schemas' => [
                    'Pet' => ['type' => 'object', 'required' => ['id']],
                    'Named' => [
                        'allOf' => [
                            ['$ref' => '#/components/schemas/Pet'],
                            ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
                        ],
                    ],
                ],
            ],
            'x-alias' => ['$ref' => '#/components/schemas/Named'],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $this->assertSame(
            ['type' => 'object', 'required' => ['id']],
            $resolved['x-alias']['allOf'][0],
        );
    }

    #[Test]
    public function throws_on_external_file_ref(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'Ref' => ['$ref' => 'other.json#/components/schemas/Pet'],
                ],
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('External $ref');

        OpenApiRefResolver::resolve($spec);
    }

    #[Test]
    public function throws_on_external_url_ref(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'Ref' => ['$ref' => 'https://example.com/spec.json#/components/schemas/Pet'],
                ],
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('External $ref');

        OpenApiRefResolver::resolve($spec);
    }

    #[Test]
    public function throws_on_direct_self_reference(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'Node' => [
                        'type' => 'object',
                        'properties' => [
                            'self' => ['$ref' => '#/components/schemas/Node'],
                        ],
                    ],
                ],
            ],
            'paths' => [
                '/nodes' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/Node'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Circular $ref');

        OpenApiRefResolver::resolve($spec);
    }

    #[Test]
    public function throws_on_indirect_circular_ref(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'A' => [
                        'type' => 'object',
                        'properties' => [
                            'b' => ['$ref' => '#/components/schemas/B'],
                        ],
                    ],
                    'B' => [
                        'type' => 'object',
                        'properties' => [
                            'a' => ['$ref' => '#/components/schemas/A'],
                        ],
                    ],
                ],
            ],
            'paths' => [
                '/a' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/A'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Circular $ref');

        OpenApiRefResolver::resolve($spec);
    }

    #[Test]
    public function throws_on_three_node_circular_ref(): void
    {
        // The 2-node case is covered above. A 3-node cycle exercises the
        // chain length beyond the trivial minimum; a regression that only
        // checks the immediate parent would slip past.
        $spec = [
            'components' => [
                'schemas' => [
                    'A' => [
                        'properties' => [
                            'next' => ['$ref' => '#/components/schemas/B'],
                        ],
                    ],
                    'B' => [
                        'properties' => [
                            'next' => ['$ref' => '#/components/schemas/C'],
                        ],
                    ],
                    'C' => [
                        'properties' => [
                            'next' => ['$ref' => '#/components/schemas/A'],
                        ],
                    ],
                ],
            ],
            'x-alias' => ['$ref' => '#/components/schemas/A'],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Circular $ref');

        OpenApiRefResolver::resolve($spec);
    }

    #[Test]
    public function throws_on_unresolvable_ref(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'Pet' => ['type' => 'object'],
                ],
            ],
            'paths' => [
                '/x' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/DoesNotExist'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unresolvable $ref');

        OpenApiRefResolver::resolve($spec);
    }

    #[Test]
    public function throws_on_unresolvable_ref_under_non_json_media_type(): void
    {
        // Regression for issue #63: a broken $ref under a non-JSON media type
        // (application/xml, text/plain, ...) must surface at load time just
        // like one under application/json. The resolver walks every node, so
        // media-type keys are irrelevant — this test pins that invariant so a
        // future "skip non-JSON branches" optimization cannot silently mask
        // unresolvable refs.
        $spec = [
            'components' => [
                'schemas' => [
                    'Pet' => ['type' => 'object'],
                ],
            ],
            'paths' => [
                '/x' => [
                    'post' => [
                        'requestBody' => [
                            'content' => [
                                'application/xml' => [
                                    'schema' => ['$ref' => '#/components/schemas/DoesNotExist'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unresolvable $ref');

        OpenApiRefResolver::resolve($spec);
    }

    #[Test]
    public function throws_on_non_string_ref(): void
    {
        $spec = [
            'paths' => [
                '/x' => [
                    'post' => [
                        'requestBody' => [
                            '$ref' => ['not', 'a', 'string'],
                        ],
                    ],
                ],
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid $ref');

        OpenApiRefResolver::resolve($spec);
    }

    #[Test]
    public function throws_when_ref_target_is_not_an_object(): void
    {
        // Refs must point to object/array structures (schemas, responses, parameters, etc).
        // A ref landing on a scalar (e.g. info.version) is a malformed spec.
        $spec = [
            'info' => ['version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Bad' => ['$ref' => '#/info/version'],
                ],
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not an object');

        OpenApiRefResolver::resolve($spec);
    }

    #[Test]
    public function throws_when_ref_target_is_literal_null_not_unresolvable(): void
    {
        // A key that exists but holds `null` is a present-but-invalid target.
        // Distinguishing this from a genuinely missing segment avoids telling
        // the author "target not found" when the target is right there.
        $spec = [
            'x-placeholder' => null,
            'components' => [
                'schemas' => [
                    'Bad' => ['$ref' => '#/x-placeholder'],
                ],
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not an object');

        OpenApiRefResolver::resolve($spec);
    }

    #[Test]
    public function throws_on_bare_fragment_ref_with_clear_message(): void
    {
        // A bare fragment like '#foo' or just '#' is neither a JSON Pointer
        // nor an external URL. Surface it with a dedicated message so the
        // author doesn't mistake it for a bundling instruction.
        $spec = [
            'components' => [
                'schemas' => [
                    'Bad' => ['$ref' => '#foo'],
                ],
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bare fragment');

        OpenApiRefResolver::resolve($spec);
    }

    #[Test]
    public function throws_on_root_pointer_ref(): void
    {
        // `$ref: "#/"` points at the spec root. Substituting it would recurse
        // unboundedly before cycle detection kicks in; reject with a specific
        // message so the author sees the real problem.
        $spec = [
            'x-bad' => ['$ref' => '#/'],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('root pointer');

        OpenApiRefResolver::resolve($spec);
    }

    #[Test]
    public function resolves_the_same_ref_from_multiple_sites(): void
    {
        // A schema referenced twice from different operations should resolve
        // at both sites without any cross-talk or cycle false-positive.
        $spec = [
            'components' => [
                'schemas' => [
                    'Pet' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
                ],
            ],
            'paths' => [
                '/a' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/Pet'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                '/b' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/Pet'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $expected = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]];
        $this->assertSame($expected, $resolved['paths']['/a']['get']['responses']['200']['content']['application/json']['schema']);
        $this->assertSame($expected, $resolved['paths']['/b']['get']['responses']['200']['content']['application/json']['schema']);
    }

    #[Test]
    public function resolves_refs_across_mixed_json_and_non_json_content(): void
    {
        // Regression for issue #63: when a single requestBody declares both a
        // JSON and a non-JSON media type, every $ref under either key must be
        // resolved. The original concern was foreach insertion order inside
        // the validator; PR #77 moved ref handling to the loader, so the same
        // guarantee now lives here. Pinning it prevents a future "resolve only
        // the first JSON-compatible branch" optimization from silently
        // leaving non-JSON refs untouched.
        $spec = [
            'components' => [
                'schemas' => [
                    'Pet' => ['type' => 'object', 'required' => ['name']],
                ],
            ],
            'paths' => [
                '/pets' => [
                    'post' => [
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/Pet'],
                                ],
                                'application/xml' => [
                                    'schema' => ['$ref' => '#/components/schemas/Pet'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $expectedSchema = ['type' => 'object', 'required' => ['name']];
        $content = $resolved['paths']['/pets']['post']['requestBody']['content'];
        $this->assertSame($expectedSchema, $content['application/json']['schema']);
        $this->assertSame($expectedSchema, $content['application/xml']['schema']);
    }
}
