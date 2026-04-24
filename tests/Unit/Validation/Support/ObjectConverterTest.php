<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Support;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Studio\OpenApiContractTesting\Validation\Support\ObjectConverter;

use function json_encode;

class ObjectConverterTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideConvert_matches_json_roundtripCases(): iterable
    {
        yield 'null' => [null];
        yield 'string' => ['hello'];
        yield 'integer' => [42];
        yield 'float' => [3.14];
        yield 'boolean true' => [true];
        yield 'boolean false' => [false];
        yield 'empty array' => [[]];
        yield 'sequential array' => [[1, 2, 3]];
        yield 'associative array' => [['key' => 'value', 'num' => 1]];
        yield 'nested associative' => [['a' => ['b' => ['c' => 'deep']]]];
        yield 'list of objects' => [[['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]];
        yield 'non-sequential int keys' => [[1 => 'a', 3 => 'b']];
        yield 'mixed nested' => [
            [
                'users' => [
                    ['id' => 1, 'tags' => ['admin', 'user'], 'meta' => ['active' => true]],
                ],
                'total' => 1,
                'filters' => [],
            ],
        ];
        yield 'numeric string keys' => [['200' => ['description' => 'OK']]];
        yield 'deeply nested list' => [[[['a']]]];
        yield 'null in array' => [[null, 'a', null]];
        yield 'empty nested object' => [['data' => []]];
    }

    #[Test]
    #[DataProvider('provideConvert_matches_json_roundtripCases')]
    public function convert_matches_json_roundtrip(mixed $input): void
    {
        $actual = ObjectConverter::convert($input);

        // Re-encode both to JSON to compare structural equivalence
        // without relying on object identity (assertSame fails on stdClass).
        $expectedJson = json_encode($input, JSON_THROW_ON_ERROR);
        $actualJson = (string) json_encode($actual, JSON_THROW_ON_ERROR);

        $this->assertSame($expectedJson, $actualJson);
    }

    #[Test]
    public function convert_returns_scalars_untouched(): void
    {
        $this->assertSame('x', ObjectConverter::convert('x'));
        $this->assertSame(7, ObjectConverter::convert(7));
        $this->assertNull(ObjectConverter::convert(null));
    }

    #[Test]
    public function convert_turns_associative_array_into_stdclass(): void
    {
        $result = ObjectConverter::convert(['a' => 1, 'b' => 2]);

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertSame(1, $result->a);
        $this->assertSame(2, $result->b);
    }

    #[Test]
    public function convert_keeps_sequential_list_as_array(): void
    {
        $result = ObjectConverter::convert(['x', 'y']);

        $this->assertSame(['x', 'y'], $result);
    }
}
