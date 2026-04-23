<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\SkipOpenApi;
use Studio\OpenApiContractTesting\SkipOpenApiResolver;

class SkipOpenApiResolverTest extends TestCase
{
    use SkipOpenApiResolver;

    #[Test]
    public function no_attribute_returns_false(): void
    {
        $this->assertFalse($this->shouldSkipOpenApi());
        $this->assertNull($this->findSkipOpenApiAttribute());
    }

    #[Test]
    #[SkipOpenApi]
    public function method_level_attribute_skips(): void
    {
        $this->assertTrue($this->shouldSkipOpenApi());
        $this->assertSame('', $this->findSkipOpenApiAttribute()->reason);
    }

    #[Test]
    #[SkipOpenApi(reason: 'experimental endpoint')]
    public function method_level_reason_is_resolved(): void
    {
        $this->assertTrue($this->shouldSkipOpenApi());
        $this->assertSame('experimental endpoint', $this->findSkipOpenApiAttribute()->reason);
    }
}
