<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Response;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\OpenApiVersion;
use Studio\OpenApiContractTesting\Validation\Response\ResponseBodyValidator;
use Studio\OpenApiContractTesting\Validation\Support\SchemaValidatorRunner;

class ResponseBodyValidatorTest extends TestCase
{
    private ResponseBodyValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ResponseBodyValidator(new SchemaValidatorRunner(20));
    }

    #[Test]
    public function validate_passes_valid_json_body_against_schema(): void
    {
        $content = [
            'application/json' => [
                'schema' => [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                    'required' => ['id'],
                ],
            ],
        ];

        $errors = $this->validator->validate(
            'spec',
            'GET',
            '/pets/{id}',
            200,
            $content,
            ['id' => 1],
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function validate_flags_empty_body_against_json_schema(): void
    {
        $content = [
            'application/json' => ['schema' => ['type' => 'object']],
        ];

        $errors = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            200,
            $content,
            null,
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Response body is empty', $errors[0]);
    }

    #[Test]
    public function validate_accepts_non_json_content_type_when_defined_in_spec(): void
    {
        $content = [
            'text/plain' => ['schema' => ['type' => 'string']],
        ];

        $errors = $this->validator->validate(
            'spec',
            'GET',
            '/robots.txt',
            200,
            $content,
            'User-agent: *',
            'text/plain; charset=utf-8',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function validate_flags_non_json_content_type_not_in_spec(): void
    {
        $content = [
            'application/json' => ['schema' => ['type' => 'object']],
        ];

        $errors = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            200,
            $content,
            'blob',
            'application/xml',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString("Content-Type 'application/xml' is not defined", $errors[0]);
    }

    #[Test]
    public function validate_returns_empty_when_no_json_content_defined(): void
    {
        // Non-JSON spec entries with no Content-Type header → out-of-scope, pass.
        $content = ['application/xml' => ['schema' => ['type' => 'string']]];

        $errors = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            200,
            $content,
            '<pets/>',
            null,
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function validate_flags_schema_mismatch(): void
    {
        $content = [
            'application/json' => [
                'schema' => [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                    'required' => ['id'],
                ],
            ],
        ];

        $errors = $this->validator->validate(
            'spec',
            'GET',
            '/pets/{id}',
            200,
            $content,
            ['id' => 'not-an-int'],
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('/id', $errors[0]);
    }
}
