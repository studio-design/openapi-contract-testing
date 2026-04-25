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

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets/{id}',
            200,
            $content,
            ['id' => 1],
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
        $this->assertSame('application/json', $result->matchedContentType);
    }

    #[Test]
    public function validate_flags_empty_body_against_json_schema(): void
    {
        $content = [
            'application/json' => ['schema' => ['type' => 'object']],
        ];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            200,
            $content,
            null,
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('Response body is empty', $result->errors[0]);
        $this->assertSame('application/json', $result->matchedContentType);
    }

    #[Test]
    public function validate_accepts_non_json_content_type_when_defined_in_spec(): void
    {
        $content = [
            'text/plain' => ['schema' => ['type' => 'string']],
        ];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/robots.txt',
            200,
            $content,
            'User-agent: *',
            'text/plain; charset=utf-8',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
        $this->assertSame('text/plain', $result->matchedContentType);
    }

    #[Test]
    public function validate_preserves_spec_content_type_casing(): void
    {
        // The spec author wrote a mixed-case media type — the matched key
        // should keep that casing so coverage reports show it verbatim.
        $content = [
            'Application/Problem+JSON' => [
                'schema' => ['type' => 'object'],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            422,
            $content,
            ['detail' => 'oops'],
            'application/problem+json',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
        $this->assertSame('Application/Problem+JSON', $result->matchedContentType);
    }

    #[Test]
    public function validate_flags_non_json_content_type_not_in_spec(): void
    {
        $content = [
            'application/json' => ['schema' => ['type' => 'object']],
        ];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            200,
            $content,
            'blob',
            'application/xml',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString("Content-Type 'application/xml' is not defined", $result->errors[0]);
        $this->assertNull($result->matchedContentType);
    }

    #[Test]
    public function validate_returns_empty_when_no_json_content_defined(): void
    {
        // Non-JSON spec entries with no Content-Type header → out-of-scope, pass.
        $content = ['application/xml' => ['schema' => ['type' => 'string']]];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            200,
            $content,
            '<pets/>',
            null,
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
        $this->assertNull($result->matchedContentType);
    }

    #[Test]
    public function validate_accepts_empty_object_body_against_type_object(): void
    {
        // PHP's `json_decode('{}', true) === []` — the Laravel trait's
        // associative-array decoding loses the {} vs [] distinction. Without
        // schema-aware coercion the validator would reject `[]` against
        // `type: object`. Pin the fix so empty-{} responses validate.
        $content = [
            'application/json' => [
                'schema' => ['type' => 'object'],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/p',
            200,
            $content,
            [],
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
    }

    #[Test]
    public function validate_accepts_empty_object_body_against_oas_31_nullable_object(): void
    {
        // OAS 3.1 type-array form: `type: ["object", "null"]`. Same coercion.
        $content = [
            'application/json' => [
                'schema' => ['type' => ['object', 'null']],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/p',
            200,
            $content,
            [],
            'application/json',
            OpenApiVersion::V3_1,
        );

        $this->assertSame([], $result->errors);
    }

    #[Test]
    public function validate_does_not_coerce_empty_array_when_schema_is_array_type(): void
    {
        // Coercion must NOT fire when the schema actually wants an array —
        // an empty array is a legitimate value for `type: array` (with no
        // minItems constraint).
        $content = [
            'application/json' => [
                'schema' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/p',
            200,
            $content,
            [],
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
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

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets/{id}',
            200,
            $content,
            ['id' => 'not-an-int'],
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('/id', $result->errors[0]);
        $this->assertSame('application/json', $result->matchedContentType);
    }
}
