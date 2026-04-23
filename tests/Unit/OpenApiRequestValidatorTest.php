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
        $this->assertStringContainsString('expected object, got scalar', $result->errors()[0]);
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
    public function scalar_content_media_type_returns_failure(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'POST',
            '/scalar-content-media-type',
            [],
            [],
            ['foo' => 'bar'],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString("Malformed 'requestBody.content[\"application/json\"]'", $result->errors()[0]);
        $this->assertStringContainsString('expected object, got scalar', $result->errors()[0]);
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

    // ========================================
    // Path parameter validation
    // ========================================

    #[Test]
    public function path_params_valid_integer_passes(): void
    {
        // petId is declared `type: integer, minimum: 1` — "42" coerces to int 42 and passes.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/42',
            [],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
        $this->assertSame('/v1/pets/{petId}', $result->matchedPath());
    }

    #[Test]
    public function path_params_integer_expected_string_fails(): void
    {
        // Acceptance: integer 期待で文字列 → surface [path.petId] type error.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/not-a-number',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[path.petId]', $result->errorMessage());
    }

    #[Test]
    public function path_params_integer_violates_minimum_fails(): void
    {
        // Coercion succeeds but schema constraint (minimum:1) rejects 0.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/0',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[path.petId]', $result->errorMessage());
    }

    #[Test]
    public function path_params_uuid_format_valid_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/orders/f47ac10b-58cc-4372-a567-0e02b2c3d479',
            [],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
        $this->assertSame('/v1/orders/{orderId}', $result->matchedPath());
    }

    #[Test]
    public function path_params_uuid_format_violation_fails(): void
    {
        // Acceptance: uuid 違反 → surface [path.orderId] format error.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/orders/not-a-uuid',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[path.orderId]', $result->errorMessage());
    }

    #[Test]
    public function path_params_percent_encoded_value_decoded_for_validation(): void
    {
        // Canonical UUID with a literal hyphen replaced by "%2D" (percent-encoded '-').
        // Without rawurldecode() the string `f47ac10b%2D58cc-4372-a567-0e02b2c3d479`
        // would fail uuid format validation; with decoding it is the valid canonical form.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/orders/f47ac10b%2D58cc-4372-a567-0e02b2c3d479',
            [],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
        $this->assertSame('/v1/orders/{orderId}', $result->matchedPath());
    }

    #[Test]
    public function path_params_no_schema_surfaces_error(): void
    {
        // Path parameters are always required (OpenAPI spec) — a declared `in: path`
        // entry without a schema is a malformed spec and should surface as a hard error
        // rather than silently passing every request.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/widgets/anything',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[path.widgetId]', $result->errors()[0]);
    }

    #[Test]
    public function v31_path_params_uuid_format_violation_fails(): void
    {
        // OAS 3.1 parity: the 3.1 fixture declares orderId as `type: ["string"], format: uuid`
        // (multi-type array form). The format validator must still fire.
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/orders/still-not-a-uuid',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[path.orderId]', $result->errorMessage());
    }

    #[Test]
    public function v31_path_params_uuid_format_valid_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/orders/f47ac10b-58cc-4372-a567-0e02b2c3d479',
            [],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
        $this->assertSame('/v1/orders/{orderId}', $result->matchedPath());
    }

    #[Test]
    public function path_params_date_time_format_valid_passes(): void
    {
        // Pin the docblock claim that opis's built-in FormatResolver handles date-time
        // without additional configuration. A future refactor of OpenApiSchemaConverter
        // that accidentally strips `format` for path params would fail this test.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/events/2026-04-23T10:00:00Z',
            [],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
        $this->assertSame('/v1/events/{eventTime}', $result->matchedPath());
    }

    #[Test]
    public function path_params_date_time_format_violation_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/events/not-a-timestamp',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[path.eventTime]', $result->errorMessage());
    }

    #[Test]
    public function path_params_integer_with_trailing_space_fails(): void
    {
        // filter_var(FILTER_VALIDATE_INT) accepts "5 " as 5; combined with rawurldecode("5%20"),
        // this would silently launder a whitespace-polluted URL into a valid integer.
        // Real HTTP servers typically reject such paths — the validator should mirror them.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/5%20',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[path.petId]', $result->errorMessage());
    }

    #[Test]
    public function path_params_integer_with_leading_plus_fails(): void
    {
        // filter_var accepts "+5" as 5. OpenAPI's `style: simple` path serialization does
        // not emit a leading sign; accepting it silently passes a non-canonical value.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/+5',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[path.petId]', $result->errorMessage());
    }

    #[Test]
    public function path_params_scalar_entry_surfaces_error(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/path-scalar-parameter/123',
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
    public function path_placeholder_without_declaration_surfaces_error(): void
    {
        // A template with `{id}` but no matching `in: path` parameter declaration is
        // a malformed spec per OpenAPI (every placeholder MUST be declared). Silently
        // accepting any value would be a classic drift-hiding silent pass.
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/path-undeclared/anything',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[path.id]', $result->errorMessage());
        $this->assertStringContainsString('not declared', $result->errorMessage());
    }

    #[Test]
    public function path_params_declared_but_not_in_template_surfaces_error(): void
    {
        // Inverse of the above: a spec declares `in: path` name: wrongId but the template
        // uses {id}. Defensive check should surface this so the author fixes the typo.
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/path-name-mismatch/123',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[path.wrongId]', $result->errorMessage());
        $this->assertStringContainsString('not captured', $result->errorMessage());
    }

    #[Test]
    public function path_and_query_errors_are_combined(): void
    {
        // GET /v1/pets/{petId} with:
        //   - invalid path (petId = "abc" — not an integer)
        //   - invalid query (traceId = "lower" fails the operation-level ^[A-Z]+$ pattern)
        // Both errors must surface in a single result so users see the full diagnostic
        // in one run. (GET has no body — the POST /v1/pets endpoint that defines a body
        // has no path parameter, so body composition is covered by
        // query_and_body_errors_are_combined above.)
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/abc',
            ['traceId' => 'lower'],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[path.petId]', $result->errorMessage());
        $this->assertStringContainsString('[query.traceId]', $result->errorMessage());
    }

    // ========================================
    // Header parameter validation (in: header)
    // ========================================

    #[Test]
    public function header_params_valid_uuid_passes(): void
    {
        // Lower-case request key ('x-request-id') against spec name 'X-Request-ID' —
        // HTTP headers are case-insensitive (RFC 7230), so the match must hold.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            ['x-request-id' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
        $this->assertSame('/v1/reports', $result->matchedPath());
    }

    #[Test]
    public function header_params_required_missing_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[header.X-Request-ID]', $result->errorMessage());
        $this->assertStringContainsString('missing', $result->errorMessage());
    }

    #[Test]
    public function header_params_uuid_format_violation_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            ['X-Request-ID' => 'not-a-uuid'],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[header.X-Request-ID]', $result->errorMessage());
    }

    #[Test]
    public function header_params_pattern_violation_fails(): void
    {
        // X-Trace-Id: ^[A-Z0-9-]+$ pattern — lower-case value must fail.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            [
                'X-Request-ID' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
                'X-Trace-Id' => 'lower-case',
            ],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[header.X-Trace-Id]', $result->errorMessage());
    }

    #[Test]
    public function header_params_integer_coerced_passes(): void
    {
        // HTTP headers are strings on the wire; string→int coercion must fire.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            [
                'X-Request-ID' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
                'X-Page-Size' => '42',
            ],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function header_params_integer_bad_value_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            [
                'X-Request-ID' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
                'X-Page-Size' => 'abc',
            ],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[header.X-Page-Size]', $result->errorMessage());
    }

    #[Test]
    public function header_params_optional_absent_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            ['X-Request-ID' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function header_params_reserved_accept_ignored(): void
    {
        // Per OAS 3.x: "If in is header and the name field is Accept, Content-Type
        // or Authorization, the parameter definition SHALL be ignored." The fixture
        // declares Accept with a deliberately unmatchable enum — if our code still
        // validated it, the request would always fail. It does not, so pass.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            ['X-Request-ID' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function header_params_single_element_array_is_unwrapped(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            ['X-Request-ID' => ['f47ac10b-58cc-4372-a567-0e02b2c3d479']],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function header_params_multi_value_array_surfaces_hard_error(): void
    {
        // Laravel picks first / Symfony picks last — silently choosing one would
        // hide whichever value the framework under test actually uses. Refuse.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            ['X-Request-ID' => ['f47ac10b-58cc-4372-a567-0e02b2c3d479', 'also-a-value']],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[header.X-Request-ID]', $result->errorMessage());
        $this->assertStringContainsString('multiple values', $result->errorMessage());
        $this->assertStringContainsString('count=2', $result->errorMessage());
    }

    #[Test]
    public function header_params_empty_array_treated_as_missing(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            ['X-Request-ID' => []],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[header.X-Request-ID]', $result->errorMessage());
        $this->assertStringContainsString('missing', $result->errorMessage());
    }

    #[Test]
    public function header_params_explicit_null_treated_as_missing(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            ['X-Request-ID' => null],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[header.X-Request-ID]', $result->errorMessage());
        $this->assertStringContainsString('missing', $result->errorMessage());
    }

    #[Test]
    public function header_params_enum_violation_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            [
                'X-Request-ID' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
                'X-Category' => 'delta',
            ],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[header.X-Category]', $result->errorMessage());
    }

    #[Test]
    public function header_params_enum_valid_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            [
                'X-Request-ID' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
                'X-Category' => 'beta',
            ],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function header_params_reserved_content_type_ignored(): void
    {
        // The /v1/reports fixture declares Content-Type with an unmatchable enum.
        // If the reserved-skip list ever regresses, the call would fail required /
        // enum checks simultaneously. It does not, so pass.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            ['X-Request-ID' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function header_params_reserved_names_case_insensitive_ignored(): void
    {
        // Reserved-list comparison is lower-cased. Spec-declared `Accept` /
        // `Content-Type` / `Authorization` are ignored regardless of casing,
        // AND caller-supplied values at any casing are also ignored — nothing
        // about them flows into validation.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            [
                'X-Request-ID' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
                'ACCEPT' => 'x',
                'content-type' => 'x',
                'AUTHORIZATION' => 'x',
            ],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function header_params_duplicate_case_variants_last_wins(): void
    {
        // HTTP headers are case-insensitive so two keys that fold to the same
        // lower-case name refer to the same header. Later entries overwrite
        // earlier ones — pinning behaviour so future refactors can't silently
        // change which value gets validated.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            [
                'X-Request-ID' => 'not-a-uuid',
                'x-request-id' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
            ],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function header_params_non_string_key_skipped(): void
    {
        // Integer keys (rare, but possible for array literal casts) must not
        // crash strtolower() — they are silently dropped because no spec name
        // can ever match them.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            [
                0 => 'junk',
                'X-Request-ID' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
            ],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function header_params_no_schema_surfaces_error(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/header-required-no-schema',
            [],
            ['X-Api-Key' => 'anything'],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[header.X-Api-Key]', $result->errors()[0]);
    }

    #[Test]
    public function v31_header_params_uuid_format_violation_fails(): void
    {
        // OAS 3.1 parity: 3.1 fixture uses multi-type form (`type: ["string"]`).
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/reports',
            [],
            ['X-Request-ID' => 'still-not-a-uuid'],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[header.X-Request-ID]', $result->errorMessage());
    }

    #[Test]
    public function v31_header_params_uuid_format_valid_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/reports',
            [],
            ['X-Request-ID' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function path_query_header_and_body_errors_are_combined(): void
    {
        // PATCH /v1/pets/{petId} inherits path-level petId + traceId, declares an
        // operation-level X-Request-ID header, and accepts an optional JSON body.
        // Inject one failing value per source to prove all four error channels
        // compose in a single result — the single regression guard that prevents
        // any validate-and-return-early refactor from silently masking drift.
        $result = $this->validator->validate(
            'petstore-3.0',
            'PATCH',
            '/v1/pets/abc',
            ['traceId' => 'UPPER'],
            ['X-Request-ID' => 'not-a-uuid'],
            ['name' => 123],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $message = $result->errorMessage();
        $this->assertStringContainsString('[path.petId]', $message);
        $this->assertStringContainsString('[query.traceId]', $message);
        $this->assertStringContainsString('[header.X-Request-ID]', $message);
        $this->assertStringContainsString('/name', $message);
    }

    #[Test]
    public function path_query_header_body_and_security_errors_all_compose(): void
    {
        // POST /v1/secure/compose/{tenantId} bundles every validation channel the
        // pipeline offers: path param (integer), query param (pattern), header
        // (uuid), body (required 'name'), and security (bearerAuth). Inject a
        // failing value per source so a refactor that short-circuits after any
        // single channel fails would break this test.
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/secure/compose/abc',
            ['trace' => 'UPPER'],
            ['X-Request-ID' => 'not-a-uuid'],
            ['name' => 123],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $message = $result->errorMessage();
        $this->assertStringContainsString('[path.tenantId]', $message);
        $this->assertStringContainsString('[query.trace]', $message);
        $this->assertStringContainsString('[header.X-Request-ID]', $message);
        $this->assertStringContainsString('[security]', $message);
        $this->assertStringContainsString('/name', $message);
    }

    // ========================================
    // Security scheme validation
    // ========================================

    #[Test]
    public function v30_security_bearer_present_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/bearer',
            [],
            ['Authorization' => 'Bearer abc123'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v30_security_bearer_missing_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/bearer',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[security]', $result->errorMessage());
        $this->assertStringContainsString('bearerAuth', $result->errorMessage());
    }

    #[Test]
    public function v30_security_bearer_wrong_scheme_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/bearer',
            [],
            ['Authorization' => 'Basic dXNlcjpwYXNz'],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[security]', $result->errorMessage());
        $this->assertStringContainsString('bearerAuth', $result->errorMessage());
    }

    #[Test]
    public function v30_security_bearer_empty_token_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/bearer',
            [],
            ['Authorization' => 'Bearer '],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[security]', $result->errorMessage());
    }

    #[Test]
    public function v30_security_bearer_scheme_name_is_case_insensitive(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/bearer',
            [],
            ['Authorization' => 'bearer abc123'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v30_security_bearer_header_name_is_case_insensitive(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/bearer',
            [],
            ['authorization' => 'Bearer abc123'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v30_security_apikey_header_present_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/apikey-header',
            [],
            ['X-API-Key' => 'k1'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v30_security_apikey_header_missing_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/apikey-header',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[security]', $result->errorMessage());
        $this->assertStringContainsString('apiKeyHeader', $result->errorMessage());
    }

    #[Test]
    public function v30_security_apikey_header_empty_value_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/apikey-header',
            [],
            ['X-API-Key' => ''],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
    }

    #[Test]
    public function v30_security_apikey_query_present_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/apikey-query',
            ['api_key' => 'k1'],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v30_security_apikey_query_missing_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/apikey-query',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[security]', $result->errorMessage());
        $this->assertStringContainsString('apiKeyQuery', $result->errorMessage());
    }

    #[Test]
    public function v30_security_apikey_cookie_present_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/apikey-cookie',
            [],
            [],
            null,
            null,
            ['session_id' => 'abc'],
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v30_security_apikey_cookie_missing_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/apikey-cookie',
            [],
            [],
            null,
            null,
            [],
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[security]', $result->errorMessage());
        $this->assertStringContainsString('apiKeyCookie', $result->errorMessage());
    }

    #[Test]
    public function v30_security_or_bearer_only_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/or',
            [],
            ['Authorization' => 'Bearer abc'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v30_security_or_apikey_only_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/or',
            [],
            ['X-API-Key' => 'k1'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v30_security_or_both_missing_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/or',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[security]', $result->errorMessage());
    }

    #[Test]
    public function v30_security_and_both_satisfied_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/and',
            [],
            [
                'Authorization' => 'Bearer abc',
                'X-API-Key' => 'k1',
            ],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v30_security_and_one_missing_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/and',
            [],
            ['Authorization' => 'Bearer abc'],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[security]', $result->errorMessage());
        $this->assertStringContainsString('apiKeyHeader', $result->errorMessage());
    }

    #[Test]
    public function v30_security_explicit_opt_out_passes_with_no_auth(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/opt-out',
            [],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v30_security_oauth2_only_silent_skips(): void
    {
        // When every requirement entry contains only unsupported schemes
        // (oauth2 / openIdConnect) we have nothing we can validate — pass
        // rather than block the test (false-negative avoidance).
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/oauth2-only',
            [],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v30_security_bearer_or_oauth2_with_bearer_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/bearer-or-oauth2',
            [],
            ['Authorization' => 'Bearer abc'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v30_security_bearer_or_oauth2_with_nothing_fails(): void
    {
        // Bearer entry is validatable and fails; OAuth2 entry is skipped.
        // Since the only validatable entry failed and no other entry passed,
        // overall result is fail.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/bearer-or-oauth2',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[security]', $result->errorMessage());
        $this->assertStringContainsString('bearerAuth', $result->errorMessage());
    }

    #[Test]
    public function v30_security_combines_with_other_errors(): void
    {
        // Bearer missing + no other issues — ensure the security error appears
        // alongside any other errors the pipeline surfaces.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/bearer',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $errors = $result->errors();
        $this->assertNotEmpty($errors);
        $this->assertTrue(
            array_filter($errors, static fn(string $e): bool => str_contains($e, '[security]')) !== [],
            'expected at least one [security] error in: ' . $result->errorMessage(),
        );
    }

    #[Test]
    public function security_undefined_scheme_is_hard_spec_error(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/security-undefined-scheme',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('ghostScheme', $result->errorMessage());
    }

    #[Test]
    public function security_scalar_entry_is_hard_spec_error(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/security-scalar-entry',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $message = $result->errorMessage();
        $this->assertStringContainsString('[security]', $message);
        $this->assertStringContainsString('index 0', $message);
    }

    #[Test]
    public function security_scheme_missing_type_is_hard_spec_error(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/security-scheme-missing-type',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('brokenScheme', $result->errorMessage());
    }

    #[Test]
    public function security_scheme_http_without_scheme_field_is_hard_spec_error(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/security-scheme-http-no-scheme-field',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('httpNoScheme', $result->errorMessage());
    }

    #[Test]
    public function security_scheme_apikey_missing_name_is_hard_spec_error(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/security-scheme-apikey-missing-name',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('apiKeyNoName', $result->errorMessage());
    }

    #[Test]
    public function security_root_level_is_inherited_when_operation_omits_security(): void
    {
        $result = $this->validator->validate(
            'security-root',
            'GET',
            '/inherits',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[security]', $result->errorMessage());
        $this->assertStringContainsString('bearerAuth', $result->errorMessage());
    }

    #[Test]
    public function security_root_level_is_inherited_and_satisfied(): void
    {
        $result = $this->validator->validate(
            'security-root',
            'GET',
            '/inherits',
            [],
            ['Authorization' => 'Bearer abc'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function security_operation_empty_array_opts_out_of_root(): void
    {
        $result = $this->validator->validate(
            'security-root',
            'GET',
            '/opts-out',
            [],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function security_operation_override_replaces_root(): void
    {
        // /overrides replaces root-level bearerAuth with apiKeyHeader.
        // Passing a bearer should fail (because apiKey is now required).
        $result = $this->validator->validate(
            'security-root',
            'GET',
            '/overrides',
            [],
            ['Authorization' => 'Bearer abc'],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('apiKeyHeader', $result->errorMessage());
    }

    #[Test]
    public function security_operation_override_satisfied_passes(): void
    {
        $result = $this->validator->validate(
            'security-root',
            'GET',
            '/overrides',
            [],
            ['X-API-Key' => 'k1'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v31_security_bearer_present_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/secure/bearer',
            [],
            ['Authorization' => 'Bearer abc123'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v31_security_apikey_query_missing_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/secure/apikey-query',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('apiKeyQuery', $result->errorMessage());
    }

    #[Test]
    public function v31_security_or_both_missing_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/secure/or',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[security]', $result->errorMessage());
    }

    #[Test]
    public function v31_security_oauth2_only_silent_skips(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/secure/oauth2-only',
            [],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function security_unknown_type_is_hard_spec_error(): void
    {
        // A typo like type: "htpp" must not silently fall through as "unsupported".
        // If it did, every request for that endpoint would silently pass — which
        // is exactly the drift this library exists to catch.
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/security-scheme-unknown-type',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('typoScheme', $result->errorMessage());
        $this->assertStringContainsString('htpp', $result->errorMessage());
    }

    #[Test]
    public function security_empty_type_is_hard_spec_error(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/security-scheme-empty-type',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('emptyTypeScheme', $result->errorMessage());
    }

    #[Test]
    public function security_operation_level_scalar_is_hard_spec_error(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/security-root-scalar',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[security]', $result->errorMessage());
    }

    #[Test]
    public function security_numeric_scheme_name_is_hard_spec_error(): void
    {
        // Purely numeric JSON object keys (e.g. {"0": []}) become integer PHP
        // array keys after json_decode. The guard at the top of the entry loop
        // must catch this so a typo doesn't silently skip auth.
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/security-numeric-scheme-name',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[security]', $result->errorMessage());
        $this->assertStringContainsString('scheme name must be a string', $result->errorMessage());
    }

    #[Test]
    public function security_components_schemes_as_scalar_is_hard_spec_error(): void
    {
        // If `components.securitySchemes` itself is a scalar, don't silently
        // treat every scheme reference as "undefined" — that misdirects the
        // spec author. Surface a dedicated error pointing at the real cause.
        $result = $this->validator->validate(
            'security-schemes-scalar',
            'GET',
            '/needs-bearer',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('components.securitySchemes', $result->errorMessage());
    }

    #[Test]
    public function security_two_unsupported_entries_silent_skip_to_pass(): void
    {
        // Two entries (oauth2 + oidc) are both unsupported. With no validatable
        // entries remaining, overall result must be pass — prevents a regression
        // where "no satisfied entry" is conflated with "no evaluable entry".
        $result = $this->validator->validate(
            'security-edge-cases',
            'GET',
            '/two-unsupported-entries',
            [],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function security_and_with_unsupported_scheme_skips_entry(): void
    {
        // Single entry AND-joining bearer with oauth2 — because the entry
        // contains an unsupported scheme, phase-1 policy treats the whole entry
        // as unevaluable (we cannot check oauth2, so we cannot confirm AND).
        // Overall result: pass. Pins the documented tradeoff so a future policy
        // change is an explicit decision, not an accidental regression.
        $result = $this->validator->validate(
            'security-edge-cases',
            'GET',
            '/and-with-unsupported',
            [],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v30_security_apikey_header_name_is_case_insensitive(): void
    {
        // RFC 9110 §5.1 — HTTP header names are case-insensitive. Spec declares
        // 'X-API-Key'; request sends 'x-api-key'. Locks in the normalize-then-
        // lookup behaviour.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/apikey-header',
            [],
            ['x-api-key' => 'k1'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }
}
