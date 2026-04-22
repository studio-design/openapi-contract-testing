<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\SkipOpenApi;
use Studio\OpenApiContractTesting\SkipOpenApiResolver;

#[SkipOpenApi(reason: 'class-level')]
class SkipOpenApiResolverClassLevelTest extends TestCase
{
    use SkipOpenApiResolver;

    #[Test]
    public function class_level_attribute_skips(): void
    {
        $this->assertTrue($this->shouldSkipOpenApi());
        $this->assertSame('class-level', $this->resolveSkipOpenApiReason());
    }

    #[Test]
    #[SkipOpenApi(reason: 'method-level')]
    public function method_level_reason_overrides_class_level(): void
    {
        $this->assertTrue($this->shouldSkipOpenApi());
        $this->assertSame('method-level', $this->resolveSkipOpenApiReason());
    }
}
