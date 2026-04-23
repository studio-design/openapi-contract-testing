<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\HttpMethod;
use Studio\OpenApiContractTesting\Laravel\ValidatesOpenApiSchema;
use Studio\OpenApiContractTesting\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\Tests\Helpers\CreatesTestResponse;

use function json_encode;

// Load namespace-level config() mock before the trait resolves the function call.
require_once __DIR__ . '/../Helpers/LaravelConfigMock.php';

/**
 * Covers the per-request skipResponseCode() fluent API from issue #48.
 *
 * The flag rides on the #41 per-request consumption model: set before an HTTP
 * call, consumed (and reset) on the next auto-assert attempt. Here the "HTTP
 * call" is simulated by a direct call to maybeAutoAssertOpenApiSchema, which
 * is what createTestResponse delegates to under auto-assert.
 */
class ValidatesOpenApiSchemaSkipResponseCodeTest extends TestCase
{
    use CreatesTestResponse;
    use ValidatesOpenApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        OpenApiCoverageTracker::reset();
        $GLOBALS['__openapi_testing_config'] = [
            'openapi-contract-testing.default_spec' => 'petstore-3.0',
            'openapi-contract-testing.auto_assert' => true,
            // Disable the default 5xx skip so per-request behavior is observable
            // without interference from the config-level skip set.
            'openapi-contract-testing.skip_response_codes' => [],
        ];
    }

    protected function tearDown(): void
    {
        self::resetValidatorCache();
        unset($GLOBALS['__openapi_testing_config']);
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function skip_response_code_int_exact_match_skips_auto_assert(): void
    {
        // 503 is not defined in the spec — without the skip, auto-assert would
        // raise "Status code 503 not defined". skipResponseCode(503) must
        // suppress that failure AND still record coverage (endpoint exercised).
        $body = (string) json_encode(['error' => 'service unavailable'], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 503);

        $this->skipResponseCode(503);
        $this->maybeAutoAssertOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('petstore-3.0', $covered);
        $this->assertArrayHasKey('GET /v1/pets', $covered['petstore-3.0']);
    }

    #[Test]
    public function skip_response_code_int_does_not_match_sibling_codes(): void
    {
        // Regression guard: int 50 must be anchored to match only "50", not any
        // code starting with 50. A 503 response must still fail loudly when the
        // skip pattern is just "50".
        $response = $this->makeTestResponse('{}', 503);

        $this->skipResponseCode(50);

        try {
            $this->maybeAutoAssertOpenApiSchema($response, HttpMethod::GET, '/v1/pets');
            $this->fail('Expected AssertionFailedError was not thrown — skip_response_code(50) must not match 503.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('Status code 503 not defined', $e->getMessage());
        }
    }

    #[Test]
    public function skip_response_code_regex_string_matches_pattern(): void
    {
        // String codes are treated as regex. 404 is not in the spec, so without
        // a skip this would fail; '4\d\d' must match and suppress the failure.
        $response = $this->makeTestResponse('{}', 404);

        $this->skipResponseCode('4\d\d');
        $this->maybeAutoAssertOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('petstore-3.0', $covered);
    }

    #[Test]
    public function skip_response_code_array_expansion(): void
    {
        // Array argument must be expanded one level: mixed int + string regex
        // entries are accepted and each individually matches on separate calls.
        $this->skipResponseCode([404, '5\d\d']);
        $this->maybeAutoAssertOpenApiSchema(
            $this->makeTestResponse('{}', 404),
            HttpMethod::GET,
            '/v1/pets',
        );

        // Second call with fresh expansion — the array must have carried '5\d\d'
        // as a regex, not literalised it.
        $this->skipResponseCode([404, '5\d\d']);
        $this->maybeAutoAssertOpenApiSchema(
            $this->makeTestResponse('{}', 503),
            HttpMethod::GET,
            '/v1/pets',
        );

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('GET /v1/pets', $covered['petstore-3.0']);
    }

    #[Test]
    public function skip_response_code_variadic_args(): void
    {
        // Multiple positional arguments (without array wrapping) must all be
        // registered. Proven by exercising each separately.
        $this->skipResponseCode(404, 503);
        $this->maybeAutoAssertOpenApiSchema(
            $this->makeTestResponse('{}', 404),
            HttpMethod::GET,
            '/v1/pets',
        );

        $this->skipResponseCode(404, 503);
        $this->maybeAutoAssertOpenApiSchema(
            $this->makeTestResponse('{}', 503),
            HttpMethod::GET,
            '/v1/pets',
        );

        $this->assertArrayHasKey(
            'GET /v1/pets',
            OpenApiCoverageTracker::getCovered()['petstore-3.0'],
        );
    }

    #[Test]
    public function skip_response_code_merges_with_config_default(): void
    {
        // Per-request codes must be ADDED to the config default, not replace it.
        // Config default covers 5xx; per-request adds 4xx. A 404 call should
        // skip via per-request; a subsequent 503 call (no per-request) should
        // still skip via the config default.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.skip_response_codes'] = ['5\d\d'];

        $this->skipResponseCode('4\d\d');
        $this->maybeAutoAssertOpenApiSchema(
            $this->makeTestResponse('{}', 404),
            HttpMethod::GET,
            '/v1/pets',
        );

        // No per-request flag this time — config default alone must cover 503.
        $this->maybeAutoAssertOpenApiSchema(
            $this->makeTestResponse('{}', 503),
            HttpMethod::GET,
            '/v1/pets',
        );

        $this->assertArrayHasKey(
            'GET /v1/pets',
            OpenApiCoverageTracker::getCovered()['petstore-3.0'],
        );
    }

    #[Test]
    public function skip_response_code_resets_after_one_call(): void
    {
        // Core per-request guarantee: flag covers exactly the next HTTP call,
        // then resets. Config skip is off, so the second call with no fresh
        // skipResponseCode() must fail.
        $this->skipResponseCode(503);
        $this->maybeAutoAssertOpenApiSchema(
            $this->makeTestResponse('{}', 503),
            HttpMethod::GET,
            '/v1/pets',
        );

        $this->expectException(AssertionFailedError::class);
        $this->maybeAutoAssertOpenApiSchema(
            $this->makeTestResponse('{}', 503),
            HttpMethod::GET,
            '/v1/pets',
        );
    }

    #[Test]
    public function skip_response_code_returns_self_for_fluent_chain(): void
    {
        // The primary ergonomic reason for the method: lets tests write
        // $this->skipResponseCode(503)->get('/v1/pets').
        $this->assertSame($this, $this->skipResponseCode(503));
        $this->assertSame($this, $this->skipResponseCode('5\d\d'));
        $this->assertSame($this, $this->skipResponseCode([404, 503]));
        $this->assertSame($this, $this->skipResponseCode(404, 503));
    }

    #[Test]
    public function skip_response_code_chained_calls_accumulate(): void
    {
        // Chained calls must accumulate, not replace. Proving 503 was added by
        // the second call after 404 was added by the first implies both calls'
        // codes survived into the consumed flag.
        $this->skipResponseCode(404)->skipResponseCode(503);
        $this->maybeAutoAssertOpenApiSchema(
            $this->makeTestResponse('{}', 503),
            HttpMethod::GET,
            '/v1/pets',
        );

        $this->assertArrayHasKey(
            'GET /v1/pets',
            OpenApiCoverageTracker::getCovered()['petstore-3.0'],
        );
    }

    #[Test]
    public function skip_response_code_does_not_affect_explicit_assert(): void
    {
        // Scope is auto-assert only — matches withoutValidation()'s existing
        // convention. An explicit assertResponseMatchesOpenApiSchema() call
        // must ignore the per-request skip flag, because explicit calls are
        // the user's direct intent.
        $response = $this->makeTestResponse('{}', 503);

        $this->skipResponseCode(503);

        $this->expectException(AssertionFailedError::class);
        $this->assertResponseMatchesOpenApiSchema($response, HttpMethod::GET, '/v1/pets');
    }

    #[Test]
    public function skip_response_code_consumes_flag_even_when_auto_assert_disabled(): void
    {
        // If the flag leaked past an auto_assert=false HTTP call, toggling
        // auto_assert on mid-test would silently skip the wrong request.
        // Consumption must happen at the boundary regardless of config state,
        // mirroring how withoutValidation() behaves.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_assert'] = false;

        $this->skipResponseCode(503);
        $this->maybeAutoAssertOpenApiSchema(
            $this->makeTestResponse('{}', 503),
            HttpMethod::GET,
            '/v1/pets',
        );

        // Now enable auto-assert and send a 503 — must throw, proving the
        // flag was already consumed by the previous call.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_assert'] = true;

        $this->expectException(AssertionFailedError::class);
        $this->maybeAutoAssertOpenApiSchema(
            $this->makeTestResponse('{}', 503),
            HttpMethod::GET,
            '/v1/pets',
        );
    }
}
