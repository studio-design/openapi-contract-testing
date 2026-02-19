<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\OpenApiVersion;

class OpenApiVersionTest extends TestCase
{
    /** @return array<string, array{array<string, mixed>, OpenApiVersion}> */
    public static function provideDetects_version_from_specCases(): iterable
    {
        return [
            '3.0.0' => [['openapi' => '3.0.0'], OpenApiVersion::V3_0],
            '3.0.3' => [['openapi' => '3.0.3'], OpenApiVersion::V3_0],
            '3.1.0' => [['openapi' => '3.1.0'], OpenApiVersion::V3_1],
            '3.1.1' => [['openapi' => '3.1.1'], OpenApiVersion::V3_1],
            'missing field' => [[], OpenApiVersion::V3_0],
            'empty string' => [['openapi' => ''], OpenApiVersion::V3_0],
            'non-string value' => [['openapi' => 3], OpenApiVersion::V3_0],
        ];
    }

    /** @param array<string, mixed> $spec */
    #[Test]
    #[DataProvider('provideDetects_version_from_specCases')]
    public function detects_version_from_spec(array $spec, OpenApiVersion $expected): void
    {
        $this->assertSame($expected, OpenApiVersion::fromSpec($spec));
    }
}
