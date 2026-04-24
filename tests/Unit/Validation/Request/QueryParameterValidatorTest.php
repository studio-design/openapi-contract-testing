<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Request;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\OpenApiVersion;
use Studio\OpenApiContractTesting\Validation\Request\QueryParameterValidator;
use Studio\OpenApiContractTesting\Validation\Support\SchemaValidatorRunner;

class QueryParameterValidatorTest extends TestCase
{
    private QueryParameterValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new QueryParameterValidator(new SchemaValidatorRunner(20));
    }

    #[Test]
    public function validate_passes_matching_integer_query_parameter(): void
    {
        $parameters = [
            ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer']],
        ];

        $errors = $this->validator->validate(
            'GET',
            '/pets',
            $parameters,
            ['limit' => '10'],
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function validate_flags_missing_required_parameter(): void
    {
        $parameters = [
            ['name' => 'q', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
        ];

        $errors = $this->validator->validate('GET', '/pets', $parameters, [], OpenApiVersion::V3_0);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('required query parameter is missing', $errors[0]);
    }

    #[Test]
    public function validate_skips_optional_parameters_without_schema(): void
    {
        $parameters = [['name' => 'x', 'in' => 'query']];

        $errors = $this->validator->validate('GET', '/pets', $parameters, [], OpenApiVersion::V3_0);

        $this->assertSame([], $errors);
    }
}
