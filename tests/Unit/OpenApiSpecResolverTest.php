<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\OpenApiSpec;
use Studio\OpenApiContractTesting\OpenApiSpecResolver;

#[OpenApiSpec('petstore-3.0')]
class OpenApiSpecResolverTest extends TestCase
{
    use OpenApiSpecResolver;

    #[Test]
    public function class_level_attribute_is_resolved(): void
    {
        $this->assertSame('petstore-3.0', $this->resolveOpenApiSpec());
    }

    #[Test]
    #[OpenApiSpec('petstore-3.1')]
    public function method_level_attribute_overrides_class_level(): void
    {
        $this->assertSame('petstore-3.1', $this->resolveOpenApiSpec());
    }

    #[Test]
    public function fallback_returns_empty_string_when_no_attribute(): void
    {
        // This test class has a class-level attribute, so we verify
        // the fallback method itself returns empty by default.
        $this->assertSame('', $this->openApiSpecFallback());
    }
}
