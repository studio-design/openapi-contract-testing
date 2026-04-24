<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Support;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Validation\Support\ObjectConverter;
use Studio\OpenApiContractTesting\Validation\Support\SchemaValidatorRunner;

class SchemaValidatorRunnerTest extends TestCase
{
    #[Test]
    public function validate_returns_empty_array_for_valid_data(): void
    {
        $runner = new SchemaValidatorRunner(20);
        $schema = ObjectConverter::convert(['type' => 'integer']);

        $this->assertSame([], $runner->validate($schema, 42));
    }

    #[Test]
    public function validate_returns_formatted_errors_for_invalid_data(): void
    {
        $runner = new SchemaValidatorRunner(20);
        $schema = ObjectConverter::convert(['type' => 'integer']);

        $errors = $runner->validate($schema, 'not-an-int');

        $this->assertNotSame([], $errors);
        $this->assertArrayHasKey('/', $errors);
    }

    #[Test]
    public function validate_returns_nested_pointer_paths(): void
    {
        $runner = new SchemaValidatorRunner(20);
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'properties' => [
                'count' => ['type' => 'integer'],
            ],
            'required' => ['count'],
        ]);
        $data = ObjectConverter::convert(['count' => 'not-an-int']);

        $errors = $runner->validate($schema, $data);

        $this->assertArrayHasKey('/count', $errors);
    }

    #[Test]
    public function constructor_rejects_negative_max_errors(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maxErrors must be 0 (unlimited) or a positive integer, got -1.');

        new SchemaValidatorRunner(-1);
    }

    #[Test]
    public function constructor_accepts_zero_as_unlimited(): void
    {
        $runner = new SchemaValidatorRunner(0);

        $this->assertSame([], $runner->validate(ObjectConverter::convert(['type' => 'string']), 'ok'));
    }
}
