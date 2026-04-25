<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Response;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\OpenApiVersion;
use Studio\OpenApiContractTesting\Validation\Response\ResponseHeaderValidator;
use Studio\OpenApiContractTesting\Validation\Support\SchemaValidatorRunner;

class ResponseHeaderValidatorTest extends TestCase
{
    #[Test]
    public function returns_no_errors_when_spec_defines_no_headers(): void
    {
        $errors = $this->validator()->validate([], ['Location' => 'https://example.com/pets/1'], OpenApiVersion::V3_0);

        $this->assertSame([], $errors);
    }

    #[Test]
    public function passes_when_required_header_is_present(): void
    {
        $headersSpec = [
            'Location' => [
                'required' => true,
                'schema' => ['type' => 'string'],
            ],
        ];

        $errors = $this->validator()->validate(
            $headersSpec,
            ['Location' => 'https://example.com/pets/1'],
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function reports_missing_required_header(): void
    {
        $headersSpec = [
            'Location' => [
                'required' => true,
                'schema' => ['type' => 'string'],
            ],
        ];

        $errors = $this->validator()->validate($headersSpec, [], OpenApiVersion::V3_0);

        $this->assertSame(['[response-header.Location] required header is missing.'], $errors);
    }

    #[Test]
    public function passes_when_optional_header_is_absent(): void
    {
        $headersSpec = [
            'X-RateLimit-Remaining' => [
                'schema' => ['type' => 'integer'],
            ],
        ];

        $errors = $this->validator()->validate($headersSpec, [], OpenApiVersion::V3_0);

        $this->assertSame([], $errors);
    }

    #[Test]
    public function matches_header_names_case_insensitively(): void
    {
        // Spec uses canonical casing; actual response uses lower-case (Symfony's HeaderBag does this).
        $headersSpec = [
            'Location' => [
                'required' => true,
                'schema' => ['type' => 'string'],
            ],
        ];

        $errors = $this->validator()->validate(
            $headersSpec,
            ['location' => 'https://example.com/pets/1'],
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function reports_schema_mismatch_with_response_header_prefix(): void
    {
        $headersSpec = [
            'X-RateLimit-Remaining' => [
                'schema' => ['type' => 'integer', 'minimum' => 0],
            ],
        ];

        $errors = $this->validator()->validate(
            $headersSpec,
            ['X-RateLimit-Remaining' => '-5'],
            OpenApiVersion::V3_0,
        );

        $this->assertNotSame([], $errors);
        $this->assertStringStartsWith('[response-header.X-RateLimit-Remaining]', $errors[0]);
    }

    #[Test]
    public function coerces_string_value_to_integer_for_schema_check(): void
    {
        // HTTP header values are always strings; the validator must coerce
        // a clean integer-shaped string before checking against type: integer.
        $headersSpec = [
            'X-Count' => [
                'schema' => ['type' => 'integer'],
            ],
        ];

        $errors = $this->validator()->validate(
            $headersSpec,
            ['X-Count' => '42'],
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function reports_format_violation(): void
    {
        $headersSpec = [
            'X-Issued-At' => [
                'schema' => ['type' => 'string', 'format' => 'date-time'],
            ],
        ];

        $errors = $this->validator()->validate(
            $headersSpec,
            ['X-Issued-At' => 'not-a-date'],
            OpenApiVersion::V3_0,
        );

        $this->assertNotSame([], $errors);
        $this->assertStringStartsWith('[response-header.X-Issued-At]', $errors[0]);
    }

    #[Test]
    public function skips_content_type_header_per_oas_spec(): void
    {
        // Per OAS 3.0/3.1, "If a response header is defined with the name
        // 'Content-Type', it SHALL be ignored." Even with an absurd schema
        // mismatch, no error must surface.
        $headersSpec = [
            'Content-Type' => [
                'required' => true,
                'schema' => ['type' => 'integer'],
            ],
        ];

        $errors = $this->validator()->validate(
            $headersSpec,
            ['Content-Type' => 'application/json'],
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function skips_content_type_case_insensitively(): void
    {
        $headersSpec = [
            'content-type' => [
                'required' => true,
                'schema' => ['type' => 'integer'],
            ],
        ];

        $errors = $this->validator()->validate($headersSpec, [], OpenApiVersion::V3_0);

        $this->assertSame([], $errors);
    }

    #[Test]
    public function unwraps_single_element_array_value_from_header_bag(): void
    {
        // Symfony HeaderBag::all() returns array<string, list<string>>.
        $headersSpec = [
            'X-Count' => [
                'schema' => ['type' => 'integer'],
            ],
        ];

        $errors = $this->validator()->validate(
            $headersSpec,
            ['X-Count' => ['42']],
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function reports_multi_value_header_against_scalar_schema(): void
    {
        // Sending the same header more than once with a scalar schema is
        // ambiguous (Laravel picks first, Symfony picks last). Refusing
        // to silently choose mirrors the request-side behaviour.
        $headersSpec = [
            'X-Foo' => [
                'schema' => ['type' => 'string'],
            ],
        ];

        $errors = $this->validator()->validate(
            $headersSpec,
            ['X-Foo' => ['a', 'b']],
            OpenApiVersion::V3_0,
        );

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('multiple values', $errors[0]);
        $this->assertStringStartsWith('[response-header.X-Foo]', $errors[0]);
    }

    #[Test]
    public function treats_empty_array_value_as_absent_header(): void
    {
        $headersSpec = [
            'X-Optional' => [
                'schema' => ['type' => 'string'],
            ],
        ];

        $errors = $this->validator()->validate(
            $headersSpec,
            ['X-Optional' => []],
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function reports_required_with_no_schema_as_spec_error(): void
    {
        // Required headers with no schema would silently pass every response,
        // mirroring the request-side guard rail.
        $headersSpec = [
            'X-Token' => ['required' => true],
        ];

        $errors = $this->validator()->validate(
            $headersSpec,
            ['X-Token' => 'abc'],
            OpenApiVersion::V3_0,
        );

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('no schema', $errors[0]);
        $this->assertStringStartsWith('[response-header.X-Token]', $errors[0]);
    }

    #[Test]
    public function preserves_original_spec_casing_in_error_messages(): void
    {
        // Even when the actual header arrives lower-cased, the error
        // message must echo the spec's casing so authors can grep their
        // OpenAPI document directly.
        $headersSpec = [
            'X-RateLimit-Remaining' => [
                'required' => true,
                'schema' => ['type' => 'integer'],
            ],
        ];

        $errors = $this->validator()->validate($headersSpec, [], OpenApiVersion::V3_0);

        $this->assertSame(
            ['[response-header.X-RateLimit-Remaining] required header is missing.'],
            $errors,
        );
    }

    private function validator(): ResponseHeaderValidator
    {
        return new ResponseHeaderValidator(new SchemaValidatorRunner(20));
    }
}
