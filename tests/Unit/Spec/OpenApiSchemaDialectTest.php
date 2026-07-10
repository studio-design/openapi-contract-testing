<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Spec;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Exception\InvalidOpenApiSpecException;
use Studio\OpenApiContractTesting\Exception\InvalidOpenApiSpecReason;
use Studio\OpenApiContractTesting\OpenApiVersion;
use Studio\OpenApiContractTesting\Spec\OpenApiSchemaDialect;

final class OpenApiSchemaDialectTest extends TestCase
{
    #[Test]
    public function oas_30_always_uses_draft_07(): void
    {
        $this->assertSame(
            OpenApiSchemaDialect::DRAFT_07,
            OpenApiSchemaDialect::fromSpec(['jsonSchemaDialect' => OpenApiSchemaDialect::DRAFT_2020_12], OpenApiVersion::V3_0),
        );
    }

    #[Test]
    public function oas_31_defaults_to_the_openapi_base_dialect(): void
    {
        $this->assertSame(
            OpenApiSchemaDialect::OAS_3_1,
            OpenApiSchemaDialect::fromSpec([], OpenApiVersion::V3_1),
        );
    }

    #[Test]
    public function document_dialect_is_returned_when_supported(): void
    {
        $this->assertSame(
            OpenApiSchemaDialect::DRAFT_07,
            OpenApiSchemaDialect::fromSpec(
                ['jsonSchemaDialect' => OpenApiSchemaDialect::DRAFT_07],
                OpenApiVersion::V3_2,
            ),
        );
    }

    #[Test]
    public function unsupported_document_dialect_fails_explicitly(): void
    {
        try {
            OpenApiSchemaDialect::fromSpec(
                ['jsonSchemaDialect' => 'https://example.com/custom-dialect'],
                OpenApiVersion::V3_1,
            );
            $this->fail('Expected unsupported dialect to throw.');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::UnsupportedJsonSchemaDialect, $e->reason);
            $this->assertStringContainsString('custom-dialect', $e->getMessage());
        }
    }

    #[Test]
    public function malformed_document_dialect_fails_explicitly(): void
    {
        $this->expectException(InvalidOpenApiSpecException::class);
        $this->expectExceptionMessage('expected a non-empty URI string');

        OpenApiSchemaDialect::fromSpec(['jsonSchemaDialect' => null], OpenApiVersion::V3_1);
    }
}
