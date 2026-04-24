<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Validation\Support\TypeCoercer;

class TypeCoercerTest extends TestCase
{
    #[Test]
    public function first_primitive_type_skips_null(): void
    {
        $this->assertSame('integer', TypeCoercer::firstPrimitiveType(['null', 'integer']));
        $this->assertSame('string', TypeCoercer::firstPrimitiveType(['string']));
    }

    #[Test]
    public function first_primitive_type_returns_null_when_only_null_present(): void
    {
        $this->assertNull(TypeCoercer::firstPrimitiveType(['null']));
        $this->assertNull(TypeCoercer::firstPrimitiveType([]));
    }

    #[Test]
    public function coerce_to_int_converts_canonical_digits(): void
    {
        $this->assertSame(0, TypeCoercer::coerceToInt('0'));
        $this->assertSame(42, TypeCoercer::coerceToInt('42'));
        $this->assertSame(-7, TypeCoercer::coerceToInt('-7'));
    }

    #[Test]
    public function coerce_to_int_rejects_non_canonical(): void
    {
        // Leading zero, whitespace, plus sign, decimal — all pass through untouched.
        $this->assertSame('05', TypeCoercer::coerceToInt('05'));
        $this->assertSame('5 ', TypeCoercer::coerceToInt('5 '));
        $this->assertSame('+5', TypeCoercer::coerceToInt('+5'));
        $this->assertSame('3.14', TypeCoercer::coerceToInt('3.14'));
        $this->assertSame('abc', TypeCoercer::coerceToInt('abc'));
    }

    #[Test]
    public function coerce_primitive_from_type_handles_boolean_and_number(): void
    {
        $this->assertTrue(TypeCoercer::coercePrimitiveFromType('true', 'boolean'));
        $this->assertFalse(TypeCoercer::coercePrimitiveFromType('FALSE', 'boolean'));
        $this->assertSame(3.14, TypeCoercer::coercePrimitiveFromType('3.14', 'number'));
        $this->assertSame('maybe', TypeCoercer::coercePrimitiveFromType('maybe', 'boolean'));
    }

    #[Test]
    public function coerce_primitive_uses_first_primitive_type_for_multi_type_schema(): void
    {
        $schema = ['type' => ['null', 'integer']];

        $this->assertSame(42, TypeCoercer::coercePrimitive('42', $schema));
    }

    #[Test]
    public function coerce_query_handles_array_type(): void
    {
        $schema = ['type' => 'array', 'items' => ['type' => 'integer']];

        $this->assertSame([1, 2, 3], TypeCoercer::coerceQuery(['1', '2', '3'], $schema));
    }

    #[Test]
    public function coerce_query_wraps_scalar_when_type_is_array(): void
    {
        $schema = ['type' => 'array', 'items' => ['type' => 'integer']];

        $this->assertSame([5], TypeCoercer::coerceQuery('5', $schema));
    }
}
