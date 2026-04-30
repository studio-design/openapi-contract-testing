<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Spec;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecResolver;

class OpenApiSpecResolverFallbackTest extends TestCase
{
    use OpenApiSpecResolver;

    #[Test]
    public function fallback_is_used_when_no_attribute_present(): void
    {
        $this->assertSame('from-fallback', $this->resolveOpenApiSpec());
    }

    protected function openApiSpecFallback(): string
    {
        return 'from-fallback';
    }
}
