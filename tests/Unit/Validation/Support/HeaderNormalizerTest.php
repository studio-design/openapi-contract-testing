<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Validation\Support\HeaderNormalizer;

class HeaderNormalizerTest extends TestCase
{
    #[Test]
    public function normalize_lower_cases_string_keys(): void
    {
        $result = HeaderNormalizer::normalize(['X-Request-Id' => 'abc', 'Content-Type' => 'application/json']);

        $this->assertSame(
            ['x-request-id' => 'abc', 'content-type' => 'application/json'],
            $result,
        );
    }

    #[Test]
    public function normalize_drops_non_string_keys(): void
    {
        $result = HeaderNormalizer::normalize([0 => 'numeric', 'X-Foo' => 'bar']);

        $this->assertSame(['x-foo' => 'bar'], $result);
    }

    #[Test]
    public function normalize_collapses_duplicate_case_variants(): void
    {
        // Later entry wins — HTTP treats these as the same header.
        $result = HeaderNormalizer::normalize(['X-Foo' => 'first', 'x-foo' => 'second']);

        $this->assertSame(['x-foo' => 'second'], $result);
    }

    #[Test]
    public function normalize_preserves_value_shape(): void
    {
        $result = HeaderNormalizer::normalize(['X-Multi' => ['a', 'b']]);

        $this->assertSame(['x-multi' => ['a', 'b']], $result);
    }
}
