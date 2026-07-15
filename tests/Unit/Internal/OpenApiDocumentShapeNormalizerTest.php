<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Studio\Gesso\Internal\OpenApiDocumentShapeNormalizer;

final class OpenApiDocumentShapeNormalizerTest extends TestCase
{
    #[Test]
    public function preserves_empty_objects_only_in_structural_security_requirement_lists(): void
    {
        $document = OpenApiDocumentShapeNormalizer::normalizeResolvedDocument([
            'security' => [new stdClass()],
            'paths' => [
                '/pets' => [
                    'get' => [
                        'security' => [new stdClass()],
                        'callbacks' => [
                            'updated' => [
                                '{$request.body#/callbackUrl}' => [
                                    'post' => ['security' => [new stdClass()]],
                                ],
                            ],
                        ],
                    ],
                    'additionalOperations' => [
                        'COPY' => ['security' => [new stdClass()]],
                    ],
                ],
            ],
            'webhooks' => [
                'petUpdated' => [
                    'post' => ['security' => [new stdClass()]],
                ],
            ],
            'components' => [
                'schemas' => [
                    'Payload' => [
                        'type' => 'object',
                        'required' => ['security'],
                        'properties' => ['security' => new stdClass()],
                    ],
                ],
                'pathItems' => [
                    'Pets' => ['get' => ['security' => [new stdClass()]]],
                ],
                'callbacks' => [
                    'Updated' => [
                        '{$request.body#/callbackUrl}' => [
                            'post' => ['security' => [new stdClass()]],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertInstanceOf(stdClass::class, $document['security'][0]);
        $this->assertInstanceOf(stdClass::class, $document['paths']['/pets']['get']['security'][0]);
        $this->assertInstanceOf(
            stdClass::class,
            $document['paths']['/pets']['get']['callbacks']['updated']['{$request.body#/callbackUrl}']['post']['security'][0],
        );
        $this->assertInstanceOf(
            stdClass::class,
            $document['paths']['/pets']['additionalOperations']['COPY']['security'][0],
        );
        $this->assertInstanceOf(stdClass::class, $document['webhooks']['petUpdated']['post']['security'][0]);
        $this->assertInstanceOf(
            stdClass::class,
            $document['components']['pathItems']['Pets']['get']['security'][0],
        );
        $this->assertInstanceOf(
            stdClass::class,
            $document['components']['callbacks']['Updated']['{$request.body#/callbackUrl}']['post']['security'][0],
        );
        $this->assertSame([], $document['components']['schemas']['Payload']['properties']['security']);
    }
}
