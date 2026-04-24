<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Support;

use AssertionError;
use InvalidArgumentException;
use LogicException;
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
    public function safely_emits_fully_qualified_exception_class_name_for_namespaced_exceptions(): void
    {
        // Opis SchemaException subclasses extend RuntimeException, so the narrow
        // catch covers them. The assertion on the full FQN guards against a future
        // refactor using basename (e.g. `basename($e::class)`) that would lose
        // namespace context — critical for distinguishing opis exceptions from
        // similarly-named exceptions elsewhere.
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
        $this->assertStringContainsString('Opis\\JsonSchema\\Exceptions\\ParseException', $result[0]);
        $this->assertStringContainsString('malformed schema: bad $ref', $result[0]);
    }

    #[Test]
    public function safely_pins_exact_error_string_format(): void
    {
        // Guard against field-order / separator / label drift that
        // assertStringContainsString would silently accept. Downstream log
        // scrapers or CI summary formatters depend on this exact shape.
        $result = ValidatorErrorBoundary::safely(
            'header',
            'my-spec',
            'PATCH',
            '/v1/users/{id}',
            static function (): array {
                throw new RuntimeException('boom');
            },
        );

        $this->assertSame(
            ["[header] PATCH /v1/users/{id} in 'my-spec' spec: RuntimeException threw: boom"],
            $result,
        );
    }

    #[Test]
    public function safely_appends_previous_exception_when_present(): void
    {
        // opis wraps lower-level errors via getPrevious(); with stack traces
        // discarded, the previous class + message is the most actionable piece
        // of root-cause signal left.
        $previous = new RuntimeException('underlying PCRE error: No ending delimiter');
        $result = ValidatorErrorBoundary::safely(
            'request-body',
            'petstore',
            'POST',
            '/pets',
            static function () use ($previous): array {
                throw new RuntimeException('pattern keyword rejected', 0, $previous);
            },
        );

        $this->assertSame(
            ["[request-body] POST /pets in 'petstore' spec: RuntimeException threw: pattern keyword rejected"
                . ' (caused by RuntimeException: underlying PCRE error: No ending delimiter)'],
            $result,
        );
    }

    #[Test]
    public function safely_omits_previous_suffix_when_no_chain(): void
    {
        // Symmetric pin: an exception without getPrevious() must NOT produce
        // a dangling "(caused by ...)" suffix.
        $result = ValidatorErrorBoundary::safely(
            'request-body',
            'petstore',
            'POST',
            '/pets',
            static function (): array {
                throw new RuntimeException('lone error');
            },
        );

        $this->assertStringNotContainsString('caused by', $result[0]);
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
    public function safely_rethrows_invalid_argument_exception(): void
    {
        // InvalidArgumentException extends LogicException extends Exception — it is
        // NOT a RuntimeException, so the narrow catch lets it bubble. This mirrors
        // the \Error policy: LogicException family signals programmer bugs (e.g.
        // opis's own `throw new InvalidArgumentException("Invalid schema")`), and
        // silently downgrading those to a validation error would defeat the whole
        // point of the per-sub-validator boundary.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bad input');

        ValidatorErrorBoundary::safely(
            'request-body',
            'petstore',
            'POST',
            '/pets',
            static function (): array {
                throw new InvalidArgumentException('bad input');
            },
        );
    }

    #[Test]
    public function safely_rethrows_logic_exception(): void
    {
        // Parent of InvalidArgumentException: pins the broader LogicException
        // family policy rather than relying on the InvalidArgumentException
        // concrete case alone.
        $this->expectException(LogicException::class);

        ValidatorErrorBoundary::safely(
            'request-body',
            'petstore',
            'POST',
            '/pets',
            static function (): array {
                throw new LogicException('impossible state');
            },
        );
    }
}
