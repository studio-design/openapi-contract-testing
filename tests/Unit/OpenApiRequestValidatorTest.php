<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\OpenApiRequestValidator;
use Studio\OpenApiContractTesting\OpenApiSpecLoader;

use function array_filter;
use function str_contains;
use function strtolower;

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

    #[Test]
    public function request_body_ref_surfaces_error(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'POST',
            '/ref-request-body',
            [],
            [],
            ['name' => 'Rex'],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('RequestBody $ref encountered', $result->errors()[0]);
        $this->assertStringContainsString('#/components/requestBodies/PetBody', $result->errors()[0]);
        $this->assertStringContainsString('redocly bundle --dereference', $result->errors()[0]);
    }

    #[Test]
    public function request_body_content_media_type_ref_surfaces_error(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'POST',
            '/ref-content-media-type',
            [],
            [],
            ['name' => 'Rex'],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString("RequestBody content['application/json'] \$ref encountered", $result->errors()[0]);
        $this->assertStringContainsString('#/components/schemas/Pet', $result->errors()[0]);
        $this->assertStringContainsString('redocly bundle --dereference', $result->errors()[0]);
    }

    #[Test]
    public function request_body_content_schema_ref_surfaces_error(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'POST',
            '/ref-content-schema',
            [],
            [],
            ['name' => 'Rex'],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString("RequestBody content['application/json'].schema \$ref encountered", $result->errors()[0]);
        $this->assertStringContainsString('#/components/schemas/Pet', $result->errors()[0]);
        $this->assertStringContainsString('redocly bundle --dereference', $result->errors()[0]);
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

    #[Test]
    public function query_params_all_valid_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => '10', 'status' => 'available', 'tags' => ['a', 'b'], 'q' => 'abc'],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
        $this->assertSame('/v1/pets/search', $result->matchedPath());
    }

    #[Test]
    public function query_params_required_missing_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.limit]', $result->errors()[0]);
        $this->assertStringContainsString('required', strtolower($result->errors()[0]));
    }

    #[Test]
    public function query_params_enum_violation_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => '10', 'status' => 'unknown'],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.status]', $result->errorMessage());
    }

    #[Test]
    public function query_params_type_mismatch_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => 'abc'],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.limit]', $result->errorMessage());
    }

    #[Test]
    public function query_params_array_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => '10', 'tags' => ['a', 'b', 'c']],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function query_params_array_single_value_wrapped_passes(): void
    {
        // ?tags=a — frameworks may pass this as a scalar string for array-typed params.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => '10', 'tags' => 'a'],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function query_params_optional_missing_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => '10'],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function query_params_min_max_violation_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => '999'],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.limit]', $result->errorMessage());
    }

    #[Test]
    public function query_params_pattern_violation_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => '10', 'q' => 'hello world'],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.q]', $result->errorMessage());
    }

    #[Test]
    public function query_params_path_level_inherited(): void
    {
        // PATCH does not redeclare traceId, so the path-level rule (lowercase only) applies.
        $result = $this->validator->validate(
            'petstore-3.0',
            'PATCH',
            '/v1/pets/123',
            ['traceId' => 'ABC'],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.traceId]', $result->errorMessage());
    }

    #[Test]
    public function query_params_operation_overrides_path_level(): void
    {
        // GET redeclares traceId with uppercase-only pattern, overriding the path-level lowercase rule.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/123',
            ['traceId' => 'ABC'],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function query_params_operation_override_still_validates(): void
    {
        // GET's override requires uppercase; lowercase must fail (proves the override is checked,
        // not silently bypassed).
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/123',
            ['traceId' => 'abc'],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.traceId]', $result->errorMessage());
    }

    #[Test]
    public function query_and_body_errors_are_combined(): void
    {
        // dryRun=maybe → query type-mismatch (not a boolean)
        // body missing required "name" → body schema violation
        // Both must surface in a single result so users see the full diagnostic in one run.
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            ['dryRun' => 'maybe'],
            [],
            ['tag' => 'dog'],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.dryRun]', $result->errorMessage());
        // Body errors use opis JSON pointer paths (no "query." prefix); assert at least one such entry.
        $bodyErrors = array_filter(
            $result->errors(),
            static fn(string $err): bool => !str_contains($err, '[query.'),
        );
        $this->assertNotEmpty($bodyErrors, 'expected at least one body validation error in combined result');
    }

    #[Test]
    public function query_and_body_both_valid_passes(): void
    {
        // Mirror of the combined-error test: confirms the success path through
        // the composed validate() with both phases active and contributing zero errors.
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            ['dryRun' => 'true'],
            [],
            ['name' => 'Fido'],
            'application/json',
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function query_params_boolean_true_coerced(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            ['dryRun' => 'true'],
            [],
            ['name' => 'Fido'],
            'application/json',
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function query_params_boolean_false_coerced(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            ['dryRun' => 'false'],
            [],
            ['name' => 'Fido'],
            'application/json',
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function query_params_number_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => '5', 'score' => '0.7'],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function query_params_number_invalid_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => '5', 'score' => 'not-a-number'],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.score]', $result->errorMessage());
    }

    #[Test]
    public function query_params_no_type_schema_passes(): void
    {
        // category schema declares only `enum`, no `type` — coercion must skip,
        // and opis must accept the matching enum value untouched.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => '5', 'category' => 'dog'],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function query_params_no_type_schema_enum_violation_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => '5', 'category' => 'fish'],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.category]', $result->errorMessage());
    }

    #[Test]
    public function query_params_explicit_null_treated_as_missing(): void
    {
        // Explicit null for a required parameter should hit the same branch as "absent".
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => null],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.limit]', $result->errorMessage());
        $this->assertStringContainsString('required', strtolower($result->errorMessage()));
    }

    #[Test]
    public function query_params_explicit_null_optional_skipped(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => '5', 'status' => null],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function query_params_integer_overflow_treated_as_string(): void
    {
        // Out-of-range integer must NOT silently truncate to PHP_INT_MAX — opis
        // should see the original string and emit a type error.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => '99999999999999999999'],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.limit]', $result->errorMessage());
        $this->assertStringContainsString('type: integer', $result->errorMessage());
    }

    #[Test]
    public function query_params_integer_negative_passes(): void
    {
        // The integer schema for `count` (3.1) has minimum: 0, so use a permissive
        // schema-less coercion check via path-level traceId... actually use the
        // integer-typed `limit` with a known-passing positive value to prove
        // negative-prefix regex handling — exercised via integer-overflow test.
        // This case asserts the "clean integer" branch: filter_var accepts "-5" → -5,
        // and even though -5 fails minimum:1, it does NOT fail with a type error.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => '-5'],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringNotContainsString('type: integer', $result->errorMessage());
        $this->assertStringContainsString('greater', strtolower($result->errorMessage()));
    }

    #[Test]
    public function query_params_ref_entry_surfaces_error(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/ref-parameter',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Parameter $ref encountered', $result->errors()[0]);
        $this->assertStringContainsString('redocly bundle --dereference', $result->errors()[0]);
    }

    #[Test]
    public function query_params_scalar_entry_surfaces_error(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/scalar-parameter',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Malformed parameter entry', $result->errors()[0]);
        $this->assertStringContainsString('expected object, got scalar', $result->errors()[0]);
    }

    #[Test]
    public function query_params_required_no_schema_surfaces_error(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/required-no-schema',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.token]', $result->errors()[0]);
        $this->assertStringContainsString('no schema', $result->errors()[0]);
    }

    // ========================================
    // OAS 3.1 query parity
    // ========================================

    #[Test]
    public function v31_query_params_valid_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/pets/search',
            ['limit' => '5', 'status' => 'pending', 'tags' => ['x']],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v31_query_params_enum_violation_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/pets/search',
            ['limit' => '5', 'status' => 'unknown'],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.status]', $result->errorMessage());
    }

    #[Test]
    public function v31_query_params_multi_type_coerced_passes(): void
    {
        // count has type: ["integer", "null"] — coerceQueryValue must pick "integer"
        // as the coercion target so "42" → 42 passes the schema.
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/pets/search',
            ['limit' => '5', 'count' => '42'],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v31_query_params_multi_type_invalid_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/pets/search',
            ['limit' => '5', 'count' => 'not-a-number'],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.count]', $result->errorMessage());
    }
}
