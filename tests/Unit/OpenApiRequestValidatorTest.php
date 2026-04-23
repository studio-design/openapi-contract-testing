<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\OpenApiRequestValidator;
use Studio\OpenApiContractTesting\OpenApiSpecLoader;

class OpenApiRequestValidatorTest extends TestCase
{
    private OpenApiRequestValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        $this->validator = new OpenApiRequestValidator();
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    // ========================================
    // Acceptance criteria: valid / invalid / spec 未定義
    // ========================================

    #[Test]
    public function v30_valid_request_body_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            [],
            [],
            ['name' => 'Fido', 'tag' => 'dog'],
            'application/json',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_invalid_request_body_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            [],
            [],
            ['tag' => 'dog'],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors());
    }

    #[Test]
    public function v30_no_request_body_spec_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            [],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    // ========================================
    // Path / method resolution failures
    // ========================================

    #[Test]
    public function unknown_path_returns_failure(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/unknown',
            [],
            [],
            ['name' => 'Fido'],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('No matching path found', $result->errors()[0]);
    }

    #[Test]
    public function undefined_method_returns_failure(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'PUT',
            '/v1/pets',
            [],
            [],
            ['name' => 'Fido'],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Method PUT not defined', $result->errors()[0]);
    }

    // ========================================
    // Empty body behaviour (required vs optional)
    // ========================================

    #[Test]
    public function v30_empty_body_when_required_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            [],
            [],
            null,
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Request body is empty', $result->errors()[0]);
    }

    #[Test]
    public function v30_empty_body_when_not_required_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'PATCH',
            '/v1/pets/123',
            [],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets/{petId}', $result->matchedPath());
    }

    #[Test]
    public function v30_valid_body_when_not_required_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'PATCH',
            '/v1/pets/123',
            [],
            [],
            ['name' => 'Rex'],
            'application/json',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets/{petId}', $result->matchedPath());
    }

    #[Test]
    public function v30_invalid_body_when_not_required_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'PATCH',
            '/v1/pets/123',
            [],
            [],
            ['name' => 123],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors());
        // Must reach schema validation, not short-circuit via the required-but-empty branch.
        $this->assertStringNotContainsString('Request body is empty', $result->errorMessage());
    }

    // ========================================
    // Content negotiation
    // ========================================

    #[Test]
    public function v30_non_json_content_type_with_spec_match_succeeds(): void
    {
        // Body intentionally violates the JSON pet schema (integer, not an object with "name").
        // If the non-JSON short-circuit ever regresses into schema validation this will fail.
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            [],
            [],
            12345,
            'text/plain',
        );

        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function v30_non_json_content_type_spec_match_is_case_insensitive(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            [],
            [],
            'raw pet body',
            'TEXT/PLAIN',
        );

        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function v30_content_type_not_in_spec_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            [],
            [],
            'id,name',
            'text/csv',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString("Request Content-Type 'text/csv' is not defined for", $result->errors()[0]);
        $this->assertStringContainsString('application/json', $result->errors()[0]);
        $this->assertStringContainsString('text/plain', $result->errors()[0]);
    }

    #[Test]
    public function v30_vendor_json_content_type_validates_schema(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            [],
            [],
            ['name' => 'Fido'],
            'application/vnd.api+json',
        );

        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function v30_json_content_type_with_charset_validates_schema(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            [],
            [],
            ['name' => 'Fido'],
            'application/json; charset=utf-8',
        );

        $this->assertTrue($result->isValid());
    }

    // ========================================
    // OAS 3.1
    // ========================================

    #[Test]
    public function v31_valid_request_body_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'POST',
            '/v1/pets',
            [],
            [],
            ['name' => 'Fido', 'tag' => null],
            'application/json',
        );

        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function v31_invalid_request_body_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'POST',
            '/v1/pets',
            [],
            [],
            ['tag' => 'dog'],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors());
    }

    // ========================================
    // Schema-less JSON content type
    // ========================================

    #[Test]
    public function v30_json_content_type_without_schema_skips_validation(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'PUT',
            '/v1/pets/123',
            [],
            [],
            ['anything' => 'goes'],
            'application/json',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets/{petId}', $result->matchedPath());
    }

    // ========================================
    // Malformed spec guards
    // ========================================

    #[Test]
    public function malformed_request_body_returns_failure(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'POST',
            '/scalar-request-body',
            [],
            [],
            ['foo' => 'bar'],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString("Malformed 'requestBody'", $result->errors()[0]);
        $this->assertStringContainsString('unresolved $ref or broken spec', $result->errors()[0]);
    }

    #[Test]
    public function malformed_content_returns_failure(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'POST',
            '/scalar-content',
            [],
            [],
            ['foo' => 'bar'],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString("Malformed 'requestBody.content'", $result->errors()[0]);
    }

    // ========================================
    // Constructor validation (mirrors response validator)
    // ========================================

    #[Test]
    public function negative_max_errors_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maxErrors must be 0 (unlimited) or a positive integer, got -1.');

        new OpenApiRequestValidator(maxErrors: -1);
    }

    #[Test]
    public function max_errors_one_limits_to_single_error(): void
    {
        $validator = new OpenApiRequestValidator(maxErrors: 1);
        $result = $validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            [],
            [],
            ['name' => 123, 'tag' => 456],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertCount(1, $result->errors());
    }

    // ========================================
    // Strip prefix (reuses OpenApiPathMatcher behaviour)
    // ========================================

    #[Test]
    public function v30_strip_prefixes_applied(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs', ['/api']);

        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/api/v1/pets',
            [],
            [],
            ['name' => 'Fido'],
            'application/json',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }
}
