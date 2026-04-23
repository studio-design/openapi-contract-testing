<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use const JSON_THROW_ON_ERROR;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Studio\OpenApiContractTesting\OpenApiResponseValidator;
use Studio\OpenApiContractTesting\OpenApiSpecLoader;

use function array_map;
use function count;
use function json_encode;
use function range;

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
    // toObject equivalence tests
    // ========================================

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideTo_object_matches_json_roundtripCases(): iterable
    {
        yield 'null' => [null];
        yield 'string' => ['hello'];
        yield 'integer' => [42];
        yield 'float' => [3.14];
        yield 'boolean true' => [true];
        yield 'boolean false' => [false];
        yield 'empty array' => [[]];
        yield 'sequential array' => [[1, 2, 3]];
        yield 'associative array' => [['key' => 'value', 'num' => 1]];
        yield 'nested associative' => [['a' => ['b' => ['c' => 'deep']]]];
        yield 'list of objects' => [[['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]];
        yield 'non-sequential int keys' => [[1 => 'a', 3 => 'b']];
        yield 'mixed nested' => [
            [
                'users' => [
                    ['id' => 1, 'tags' => ['admin', 'user'], 'meta' => ['active' => true]],
                ],
                'total' => 1,
                'filters' => [],
            ],
        ];
        yield 'numeric string keys' => [['200' => ['description' => 'OK']]];
        yield 'deeply nested list' => [[[['a']]]];
        yield 'null in array' => [[null, 'a', null]];
        yield 'empty nested object' => [['data' => []]];
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function provideSkip_boundary_casesCases(): iterable
    {
        // Pin the exact 5xx boundary so that a future tweak to the default
        // pattern (e.g. accidental `5\d+` instead of `5\d\d`) cannot silently
        // widen or narrow the skip window.
        yield '499 is not skipped' => [499, false];
        yield '500 is skipped' => [500, true];
        yield '599 is skipped' => [599, true];
        yield '600 is not skipped' => [600, false];
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
    // Skip-by-status-code tests
    // ========================================

    #[Test]
    public function v30_500_response_is_skipped_by_default(): void
    {
        // petstore-3.0 defines 500 with an empty schema. The default skip
        // pattern must short-circuit even when the status code is defined,
        // so tests exercising production-only error paths stay green.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            500,
            ['error' => 'something went wrong'],
        );

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isSkipped());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_503_response_is_skipped_by_default(): void
    {
        // petstore-3.0 does NOT define 503. The default skip pattern must
        // suppress the normal "Status code 503 not defined" failure so that
        // unexpected 5xx in test environments doesn't produce extra noise.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            503,
            ['error' => 'service unavailable'],
        );

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isSkipped());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_499_response_is_not_skipped_by_default(): void
    {
        // 499 is outside the 5xx default pattern — validation should proceed
        // to the normal "Status code not defined" failure for this spec.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            499,
            ['error' => 'client closed request'],
        );

        $this->assertFalse($result->isValid());
        $this->assertFalse($result->isSkipped());
        $this->assertStringContainsString('Status code 499 not defined', $result->errors()[0]);
    }

    #[Test]
    public function v30_299_response_is_not_skipped_by_default(): void
    {
        // 299 is a 2xx, not in the default 5xx skip window. It must fall
        // through to the existing "not defined" failure.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            299,
            ['data' => []],
        );

        $this->assertFalse($result->isValid());
        $this->assertFalse($result->isSkipped());
        $this->assertStringContainsString('Status code 299 not defined', $result->errors()[0]);
    }

    #[Test]
    public function skip_response_codes_can_be_disabled(): void
    {
        // Opting out of the default skip list means 5xx behaves like any
        // other status code again — a 503 not in the spec becomes a failure.
        $validator = new OpenApiResponseValidator(skipResponseCodes: []);

        $result = $validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            503,
            ['error' => 'service unavailable'],
        );

        $this->assertFalse($result->isValid());
        $this->assertFalse($result->isSkipped());
        $this->assertStringContainsString('Status code 503 not defined', $result->errors()[0]);
    }

    #[Test]
    public function custom_skip_response_codes_pattern(): void
    {
        // Users can widen the skip set to cover 4xx or a specific code.
        $validator = new OpenApiResponseValidator(skipResponseCodes: ['4\d\d', '5\d\d']);

        $result = $validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            404,
            null,
        );

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isSkipped());
    }

    #[Test]
    public function skip_pattern_is_anchored(): void
    {
        // Anchoring matters: "50" must not match "500". Without anchors, a
        // pattern like "50" would accidentally skip any code starting with 50.
        // Also assert that validation still proceeds normally — petstore-3.0
        // defines 500 with an empty application/json schema, so the result is
        // a regular success, not a skip.
        $validator = new OpenApiResponseValidator(skipResponseCodes: ['50']);

        $result = $validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            500,
            null,
        );

        $this->assertFalse($result->isSkipped());
        $this->assertTrue($result->isValid());
    }

    #[Test]
    #[DataProvider('provideSkip_boundary_casesCases')]
    public function skip_boundary_cases(int $statusCode, bool $expectSkipped): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            $statusCode,
            null,
        );

        $this->assertSame($expectSkipped, $result->isSkipped());
    }

    #[Test]
    public function v31_5xx_response_is_skipped_by_default(): void
    {
        // petstore-3.1 does NOT define 503; skip must still fire under OAS 3.1
        // so a future move of the skip check past version-specific schema
        // conversion doesn't silently regress.
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/pets',
            503,
            ['error' => 'service unavailable'],
        );

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isSkipped());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function multiple_skip_patterns_are_ored(): void
    {
        // Regression guard: the foreach in matchingSkipPattern must try every
        // configured pattern, not short-circuit on the first.
        $validator = new OpenApiResponseValidator(skipResponseCodes: ['4\d\d', '5\d\d']);

        $result404 = $validator->validate('petstore-3.0', 'GET', '/v1/pets', 404, null);
        $result500 = $validator->validate('petstore-3.0', 'GET', '/v1/pets', 500, null);

        $this->assertTrue($result404->isSkipped());
        $this->assertTrue($result500->isSkipped());
    }

    #[Test]
    public function skip_reason_includes_matched_pattern(): void
    {
        // skipReason() carries enough detail to audit which configured pattern
        // fired, not just which status code triggered — useful when running
        // with multiple distinct patterns.
        $validator = new OpenApiResponseValidator(skipResponseCodes: ['4\d\d', '5\d\d']);

        $result = $validator->validate('petstore-3.0', 'GET', '/v1/pets', 503, null);

        $this->assertTrue($result->isSkipped());
        $this->assertSame('status 503 matched skip pattern 5\d\d', $result->skipReason());
    }

    #[Test]
    public function invalid_skip_pattern_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('skipResponseCodes');
        $this->expectExceptionMessage('(unclosed');

        new OpenApiResponseValidator(skipResponseCodes: ['(unclosed']);
    }

    #[Test]
    public function invalid_skip_pattern_error_includes_preg_detail(): void
    {
        try {
            new OpenApiResponseValidator(skipResponseCodes: ['(unclosed']);
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $e) {
            // The message carries both the offending raw pattern and a
            // preg_last_error_msg() trailer (wording varies across PHP
            // versions — "Internal error", "missing )", etc.). Assert both
            // structural pieces are present rather than locking to a
            // specific PCRE wording.
            $this->assertStringContainsString('(unclosed', $e->getMessage());
            $this->assertMatchesRegularExpression('/: \S/', $e->getMessage());
        }
    }

    #[Test]
    public function empty_skip_pattern_is_rejected(): void
    {
        // An empty pattern is never legitimate for status-code matching
        // (status codes are always non-empty), so reject it at construction
        // instead of silently accepting a regex that matches nothing.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be an empty string');

        new OpenApiResponseValidator(skipResponseCodes: ['']);
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
        // Bypass the 5xx default skip so we exercise the "content entry with
        // no schema" path specifically (petstore-3.0 defines 500 with an empty
        // application/json object). With the default skip list, the 5xx check
        // would short-circuit before reaching the schema-less branch.
        $validator = new OpenApiResponseValidator(skipResponseCodes: []);

        $result = $validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            500,
            ['error' => 'something went wrong'],
        );

        $this->assertTrue($result->isValid());
        $this->assertFalse($result->isSkipped());
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

    // ========================================
    // maxErrors tests
    // ========================================

    #[Test]
    public function default_max_errors_reports_multiple_errors(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            [
                'data' => [
                    ['id' => 'not-an-int', 'name' => 123],
                    ['id' => 'also-not-an-int', 'name' => 456],
                ],
            ],
        );

        $this->assertFalse($result->isValid());
        $this->assertGreaterThan(1, count($result->errors()));
    }

    #[Test]
    public function max_errors_caps_reported_errors_to_configured_limit(): void
    {
        $items = array_map(
            static fn(int $i) => ['id' => 'str-' . $i, 'name' => $i],
            range(1, 50),
        );

        $capped = new OpenApiResponseValidator(maxErrors: 5);
        $cappedResult = $capped->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            ['data' => $items],
        );

        $unlimited = new OpenApiResponseValidator(maxErrors: 0);
        $unlimitedResult = $unlimited->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            ['data' => $items],
        );

        $this->assertFalse($cappedResult->isValid());
        $this->assertFalse($unlimitedResult->isValid());
        $this->assertLessThan(
            count($unlimitedResult->errors()),
            count($cappedResult->errors()),
        );
    }

    #[Test]
    public function max_errors_one_limits_to_single_error(): void
    {
        $validator = new OpenApiResponseValidator(maxErrors: 1);
        $result = $validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            [
                'data' => [
                    ['id' => 'not-an-int', 'name' => 123],
                    ['id' => 'also-not-an-int', 'name' => 456],
                ],
            ],
        );

        $this->assertFalse($result->isValid());
        $this->assertCount(1, $result->errors());
    }

    #[Test]
    public function max_errors_two_reports_more_than_one_error(): void
    {
        $items = array_map(
            static fn(int $i) => ['id' => 'str-' . $i, 'name' => $i],
            range(1, 50),
        );

        $validator = new OpenApiResponseValidator(maxErrors: 2);
        $result = $validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            ['data' => $items],
        );

        $this->assertFalse($result->isValid());
        $this->assertGreaterThan(1, count($result->errors()));
    }

    #[Test]
    public function max_errors_zero_reports_all_errors(): void
    {
        $items = array_map(
            static fn(int $i) => ['id' => 'str-' . $i, 'name' => $i],
            range(1, 50),
        );

        $validator = new OpenApiResponseValidator(maxErrors: 0);
        $result = $validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            ['data' => $items],
        );

        $this->assertFalse($result->isValid());
        $this->assertGreaterThan(20, count($result->errors()));
    }

    #[Test]
    public function negative_max_errors_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maxErrors must be 0 (unlimited) or a positive integer, got -1.');

        new OpenApiResponseValidator(maxErrors: -1);
    }

    // ========================================
    // Strip prefix tests
    // ========================================

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

    #[Test]
    #[DataProvider('provideTo_object_matches_json_roundtripCases')]
    public function to_object_matches_json_roundtrip(mixed $input): void
    {
        $method = new ReflectionMethod(OpenApiResponseValidator::class, 'toObject');

        $actual = $method->invoke(null, $input);

        // Re-encode both to JSON to compare structural equivalence
        // without relying on object identity (assertSame fails on stdClass).
        $expectedJson = json_encode($input, JSON_THROW_ON_ERROR);
        $actualJson = (string) json_encode($actual, JSON_THROW_ON_ERROR);

        $this->assertSame($expectedJson, $actualJson);
    }
}
