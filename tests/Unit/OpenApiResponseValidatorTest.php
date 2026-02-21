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
    public function v30_content_without_json_compatible_type_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            415,
            '<error>Unsupported</error>',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('No JSON-compatible content type found', $result->errors()[0]);
        $this->assertStringContainsString('application/xml', $result->errors()[0]);
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
    public function v31_content_without_json_compatible_type_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'POST',
            '/v1/pets',
            415,
            '<error>Unsupported</error>',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('No JSON-compatible content type found', $result->errors()[0]);
        $this->assertStringContainsString('application/xml', $result->errors()[0]);
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
