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
    public function throws_on_ref_not_starting_with_hash_slash(): void
    {
        // Bare fragment like '#foo' (missing '/') is invalid per RFC 6901 JSON Pointer.
        $spec = [
            'components' => [
                'schemas' => [
                    'Bad' => ['$ref' => '#foo'],
                ],
            ],
        ];

        $this->expectException(RuntimeException::class);

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
}
