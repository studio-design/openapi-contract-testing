<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Support;

use AssertionError;
use Opis\JsonSchema\Exceptions\ParseException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Studio\OpenApiContractTesting\Validation\Support\ValidatorErrorBoundary;
use TypeError;

class ValidatorErrorBoundaryTest extends TestCase
{
    #[Test]
    public function safely_passes_through_return_value_when_callable_succeeds(): void
    {
        $result = ValidatorErrorBoundary::safely(
            'path',
            'petstore',
            'GET',
            '/pets/{id}',
            static fn(): array => ['[path.id] must be integer', '[path.id] must be positive'],
        );

        $this->assertSame(
            ['[path.id] must be integer', '[path.id] must be positive'],
            $result,
        );
    }

    #[Test]
    public function safely_passes_through_empty_array_on_success(): void
    {
        $result = ValidatorErrorBoundary::safely(
            'query',
            'petstore',
            'GET',
            '/pets',
            static fn(): array => [],
        );

        $this->assertSame([], $result);
    }

    #[Test]
    public function safely_converts_runtime_exception_to_error_string(): void
    {
        $result = ValidatorErrorBoundary::safely(
            'request-body',
            'petstore',
            'POST',
            '/pets',
            static function (): array {
                throw new RuntimeException('malformed schema');
            },
        );

        $this->assertCount(1, $result);
        $this->assertStringContainsString('[request-body]', $result[0]);
        $this->assertStringContainsString('POST', $result[0]);
        $this->assertStringContainsString('/pets', $result[0]);
        $this->assertStringContainsString("'petstore'", $result[0]);
        $this->assertStringContainsString('RuntimeException', $result[0]);
        $this->assertStringContainsString('malformed schema', $result[0]);
    }

    #[Test]
    public function safely_converts_opis_schema_exception_to_error_string(): void
    {
        // Opis SchemaException subclasses extend RuntimeException, so Exception catch covers them.
        $result = ValidatorErrorBoundary::safely(
            'request-body',
            'petstore',
            'POST',
            '/pets',
            static function (): array {
                throw new ParseException('malformed schema: bad $ref');
            },
        );

        $this->assertCount(1, $result);
        $this->assertStringContainsString('ParseException', $result[0]);
        $this->assertStringContainsString('malformed schema: bad $ref', $result[0]);
    }

    #[Test]
    public function safely_preserves_fully_qualified_exception_class_name(): void
    {
        $result = ValidatorErrorBoundary::safely(
            'response-body',
            'petstore',
            'GET',
            '/pets',
            static function (): array {
                throw new RuntimeException('boom');
            },
        );

        // Fully-qualified class name makes post-mortem debugging faster; don't just use basename.
        $this->assertStringContainsString('RuntimeException', $result[0]);
    }

    #[Test]
    public function safely_rethrows_type_error(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('programmer bug');

        ValidatorErrorBoundary::safely(
            'path',
            'petstore',
            'GET',
            '/pets',
            static function (): array {
                throw new TypeError('programmer bug');
            },
        );
    }

    #[Test]
    public function safely_rethrows_assertion_error(): void
    {
        $this->expectException(AssertionError::class);

        ValidatorErrorBoundary::safely(
            'security',
            'petstore',
            'GET',
            '/pets',
            static function (): array {
                throw new AssertionError('invariant broken');
            },
        );
    }

    #[Test]
    public function safely_includes_stage_method_path_specname_in_error(): void
    {
        $result = ValidatorErrorBoundary::safely(
            'header',
            'my-spec',
            'PATCH',
            '/v1/users/{id}',
            static function (): array {
                throw new RuntimeException('x');
            },
        );

        $this->assertStringContainsString('[header]', $result[0]);
        $this->assertStringContainsString('PATCH', $result[0]);
        $this->assertStringContainsString('/v1/users/{id}', $result[0]);
        $this->assertStringContainsString("'my-spec'", $result[0]);
    }
}
