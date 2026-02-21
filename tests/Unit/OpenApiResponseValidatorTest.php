<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\OpenApiResponseValidator;
use Studio\OpenApiContractTesting\OpenApiSpecLoader;

class OpenApiResponseValidatorTest extends TestCase
{
    private OpenApiResponseValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        $this->validator = new OpenApiResponseValidator();
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    // ========================================
    // OAS 3.0 tests
    // ========================================

    #[Test]
    public function v30_valid_response_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            [
                'data' => [
                    ['id' => 1, 'name' => 'Fido', 'tag' => 'dog'],
                    ['id' => 2, 'name' => 'Whiskers', 'tag' => null],
                ],
            ],
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_invalid_response_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            [
                'data' => [
                    ['id' => 'not-an-int', 'name' => 'Fido'],
                ],
            ],
        );

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors());
    }

    #[Test]
    public function v30_parameterized_path_matches(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/123',
            200,
            [
                'data' => [
                    'id' => 1,
                    'name' => 'Fido',
                    'tag' => null,
                    'owner' => null,
                ],
            ],
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets/{petId}', $result->matchedPath());
    }

    #[Test]
    public function v30_no_content_response_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'DELETE',
            '/v1/pets/123',
            204,
            null,
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets/{petId}', $result->matchedPath());
    }

    #[Test]
    public function unknown_path_returns_failure(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/unknown',
            200,
            [],
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('No matching path found', $result->errors()[0]);
    }

    #[Test]
    public function undefined_method_returns_failure(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'PATCH',
            '/v1/pets',
            200,
            [],
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Method PATCH not defined', $result->errors()[0]);
    }

    #[Test]
    public function undefined_status_code_returns_failure(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            404,
            [],
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Status code 404 not defined', $result->errors()[0]);
    }

    // ========================================
    // OAS 3.0 JSON-compatible content type tests
    // ========================================

    #[Test]
    public function v30_problem_json_valid_response_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            400,
            [
                'type' => 'https://example.com/bad-request',
                'title' => 'Bad Request',
                'status' => 400,
                'detail' => 'Invalid query parameter',
            ],
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_problem_json_invalid_response_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            400,
            [
                'type' => 'https://example.com/bad-request',
                'title' => 'Bad Request',
                'status' => 'not-an-integer',
            ],
        );

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors());
    }

    #[Test]
    public function v30_problem_json_empty_body_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            400,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Response body is empty', $result->errors()[0]);
    }

    #[Test]
    public function v30_non_json_content_type_skips_validation(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            415,
            '<error>Unsupported</error>',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_case_insensitive_content_type_matches(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            422,
            [
                'type' => 'https://example.com/validation-error',
                'title' => 'Validation Error',
                'status' => 422,
            ],
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_json_content_type_without_schema_skips_validation(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            500,
            ['error' => 'something went wrong'],
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_text_html_only_content_type_skips_validation(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/logout',
            200,
            '<html><body>Logged out</body></html>',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/logout', $result->matchedPath());
    }

    #[Test]
    public function v30_mixed_json_and_non_json_content_types_validates_json_schema(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            409,
            ['error' => 'Pet already exists'],
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_mixed_content_types_with_invalid_json_body_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            409,
            ['wrong_key' => 'value'],
        );

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors());
    }

    // ========================================
    // OAS 3.0 content negotiation tests (responseContentType parameter)
    // ========================================

    #[Test]
    public function v30_mixed_content_type_with_non_json_response_content_type_succeeds(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            409,
            null,
            'text/html',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_mixed_content_type_with_json_response_content_type_validates_schema(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            409,
            ['error' => 'Pet already exists'],
            'application/json',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_mixed_content_type_with_json_response_content_type_and_invalid_body_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            409,
            ['wrong_key' => 'value'],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors());
    }

    #[Test]
    public function v30_response_content_type_not_in_spec_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            409,
            null,
            'text/plain',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString("Response Content-Type 'text/plain' is not defined for", $result->errors()[0]);
        $this->assertStringContainsString('text/html', $result->errors()[0]);
        $this->assertStringContainsString('application/json', $result->errors()[0]);
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_response_content_type_with_charset_matches_spec(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            409,
            null,
            'text/html; charset=utf-8',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_json_response_content_type_with_charset_validates_schema(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            409,
            ['error' => 'Pet already exists'],
            'application/json; charset=utf-8',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_json_response_content_type_with_charset_and_invalid_body_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            409,
            ['wrong_key' => 'value'],
            'application/json; charset=utf-8',
        );

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors());
    }

    #[Test]
    public function v30_vendor_json_response_content_type_validates_schema(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            400,
            [
                'type' => 'https://example.com/bad-request',
                'title' => 'Bad Request',
                'status' => 400,
                'detail' => 'Invalid query parameter',
            ],
            'application/problem+json',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_case_insensitive_response_content_type_matches_spec(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            409,
            null,
            'Text/HTML',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_null_response_content_type_preserves_existing_behavior(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            409,
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Response body is empty', $result->errors()[0]);
    }

    #[Test]
    public function v30_non_json_only_spec_with_matching_response_content_type_succeeds(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/logout',
            200,
            null,
            'text/html',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/logout', $result->matchedPath());
    }

    // ========================================
    // OAS 3.1 tests
    // ========================================

    #[Test]
    public function v31_valid_response_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/pets',
            200,
            [
                'data' => [
                    ['id' => 1, 'name' => 'Fido', 'tag' => 'dog'],
                    ['id' => 2, 'name' => 'Whiskers', 'tag' => null],
                ],
            ],
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v31_invalid_response_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/pets',
            200,
            [
                'data' => [
                    ['id' => 'not-an-int', 'name' => 'Fido'],
                ],
            ],
        );

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors());
    }

    #[Test]
    public function v31_problem_json_valid_response_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/pets',
            400,
            [
                'type' => 'https://example.com/bad-request',
                'title' => 'Bad Request',
                'status' => 400,
                'detail' => null,
            ],
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v31_non_json_content_type_skips_validation(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'POST',
            '/v1/pets',
            415,
            '<error>Unsupported</error>',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v31_no_content_response_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'DELETE',
            '/v1/pets/123',
            204,
            null,
        );

        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function v30_strip_prefixes_applied(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs', ['/api']);

        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/api/v1/pets',
            200,
            [
                'data' => [
                    ['id' => 1, 'name' => 'Fido'],
                ],
            ],
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }
}
