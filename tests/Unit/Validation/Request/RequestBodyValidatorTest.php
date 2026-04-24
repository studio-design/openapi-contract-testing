<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Request;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\OpenApiVersion;
use Studio\OpenApiContractTesting\Validation\Request\RequestBodyValidator;
use Studio\OpenApiContractTesting\Validation\Support\SchemaValidatorRunner;

class RequestBodyValidatorTest extends TestCase
{
    private RequestBodyValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new RequestBodyValidator(new SchemaValidatorRunner(20));
    }

    #[Test]
    public function validate_returns_empty_when_operation_defines_no_body(): void
    {
        $errors = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            [],
            null,
            null,
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function validate_flags_missing_required_body(): void
    {
        $operation = [
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => ['schema' => ['type' => 'object']],
                ],
            ],
        ];

        $errors = $this->validator->validate(
            'spec',
            'POST',
            '/pets',
            $operation,
            null,
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Request body is empty', $errors[0]);
    }

    #[Test]
    public function validate_flags_unknown_non_json_content_type(): void
    {
        $operation = [
            'requestBody' => [
                'content' => [
                    'application/json' => ['schema' => ['type' => 'object']],
                ],
            ],
        ];

        $errors = $this->validator->validate(
            'spec',
            'POST',
            '/pets',
            $operation,
            null,
            'application/xml',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString("Content-Type 'application/xml' is not defined", $errors[0]);
    }

    #[Test]
    public function validate_flags_malformed_non_array_request_body(): void
    {
        $operation = ['requestBody' => 'oops'];

        $errors = $this->validator->validate(
            'spec',
            'POST',
            '/pets',
            $operation,
            null,
            null,
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString("Malformed 'requestBody'", $errors[0]);
    }

    #[Test]
    public function validate_flags_malformed_media_type_schema(): void
    {
        $operation = [
            'requestBody' => [
                'content' => [
                    'application/json' => ['schema' => 'not-an-array'],
                ],
            ],
        ];

        $errors = $this->validator->validate(
            'spec',
            'POST',
            '/pets',
            $operation,
            null,
            null,
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('.schema\'', $errors[0]);
        $this->assertStringContainsString('expected object, got scalar', $errors[0]);
    }

    #[Test]
    public function validate_validates_json_body_against_schema(): void
    {
        $operation = [
            'requestBody' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => ['name' => ['type' => 'string']],
                            'required' => ['name'],
                        ],
                    ],
                ],
            ],
        ];

        $errors = $this->validator->validate(
            'spec',
            'POST',
            '/pets',
            $operation,
            ['name' => 'Fido'],
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $errors);
    }
}
