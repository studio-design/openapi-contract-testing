<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Spec;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Spec\OpenApiOperationResolver;

use function array_column;

final class OpenApiOperationResolverTest extends TestCase
{
    #[Test]
    public function resolves_query_fixed_field(): void
    {
        $operation = ['operationId' => 'search'];

        $result = OpenApiOperationResolver::resolve(['query' => $operation], 'QUERY');

        $this->assertTrue($result['found']);
        $this->assertSame($operation, $result['operation']);
        $this->assertSame('query', $result['location']);
    }

    #[Test]
    public function resolves_custom_method_from_additional_operations(): void
    {
        $operation = ['operationId' => 'copy'];

        $result = OpenApiOperationResolver::resolve(
            ['additionalOperations' => ['COPY' => $operation]],
            'copy',
        );

        $this->assertTrue($result['found']);
        $this->assertSame($operation, $result['operation']);
        $this->assertSame('additionalOperations["COPY"]', $result['location']);
    }

    #[Test]
    public function does_not_treat_path_item_metadata_as_an_operation(): void
    {
        $result = OpenApiOperationResolver::resolve(['servers' => []], 'SERVERS');

        $this->assertFalse($result['found']);
    }

    #[Test]
    public function malformed_additional_operations_container_is_returned_for_loud_validation(): void
    {
        $result = OpenApiOperationResolver::resolve(['additionalOperations' => ['not-an-object-map']], 'COPY');

        $this->assertTrue($result['found']);
        $this->assertSame('additionalOperations', $result['location']);
        $this->assertSame(['not-an-object-map'], $result['operation']);
    }

    #[Test]
    public function declared_operations_include_query_and_custom_methods(): void
    {
        $result = OpenApiOperationResolver::declaredOperations([
            'get' => ['responses' => []],
            'query' => ['responses' => []],
            'additionalOperations' => ['COPY' => ['responses' => []]],
        ]);

        $this->assertSame(['GET', 'QUERY', 'COPY'], array_column($result, 'method'));
    }
}
