<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\HttpMethod;
use Studio\OpenApiContractTesting\Laravel\ValidatesOpenApiSchema;
use Studio\OpenApiContractTesting\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\OpenApiSpecLoader;
use Symfony\Component\HttpFoundation\Request;

// Load namespace-level config() mock before the trait resolves the function call.
require_once __DIR__ . '/../Helpers/LaravelConfigMock.php';

/**
 * Covers the `auto_inject_dummy_bearer` branch of request validation. The
 * inject path is a spec-driven convenience for tests that authenticate via
 * actingAs() or middleware bypass and therefore never set a real
 * Authorization header — without the inject, every bearer-protected endpoint
 * would false-fail the security check.
 */
class ValidatesOpenApiSchemaAutoInjectBearerTest extends TestCase
{
    use ValidatesOpenApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        OpenApiCoverageTracker::reset();
        $GLOBALS['__openapi_testing_config'] = [
            'openapi-contract-testing.default_spec' => 'petstore-3.0',
            'openapi-contract-testing.auto_validate_request' => true,
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
    public function config_file_defaults_auto_inject_dummy_bearer_to_false(): void
    {
        $config = require __DIR__ . '/../../src/Laravel/config.php';

        $this->assertArrayHasKey('auto_inject_dummy_bearer', $config);
        $this->assertFalse($config['auto_inject_dummy_bearer']);
    }

    #[Test]
    public function inject_true_satisfies_bearer_endpoint_without_real_header(): void
    {
        // /v1/secure/bearer requires `bearerAuth`. With the inject flag on,
        // the validator's view of the request gets a dummy Bearer token even
        // though the Symfony Request has none — security check passes.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_inject_dummy_bearer'] = true;

        $request = Request::create('/v1/secure/bearer', 'GET');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/bearer');

        $this->assertArrayHasKey(
            'GET /v1/secure/bearer',
            OpenApiCoverageTracker::getCovered()['petstore-3.0'] ?? [],
        );
    }

    #[Test]
    public function inject_false_still_fails_bearer_endpoint_without_header(): void
    {
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_inject_dummy_bearer'] = false;

        $request = Request::create('/v1/secure/bearer', 'GET');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Authorization header is missing');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/bearer');
    }

    #[Test]
    public function inject_does_not_override_user_supplied_authorization(): void
    {
        // If the test has already set Authorization (e.g. a malformed value
        // to deliberately exercise a failure path), the inject must leave it
        // alone so the test's intent wins. Here we set an invalid value and
        // assert the validator sees it.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_inject_dummy_bearer'] = true;

        $request = Request::create(
            '/v1/secure/bearer',
            'GET',
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Malformed'],
        );

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("Authorization header does not contain a 'Bearer <token>' credential");

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/bearer');
    }

    #[Test]
    public function inject_is_noop_on_apikey_only_endpoint(): void
    {
        // Inject is bearer-only by design. An apiKey endpoint still fails
        // with the apiKey-specific message so the user is directed to the
        // right fix (set the api key), not a misleading "bearer missing".
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_inject_dummy_bearer'] = true;

        $request = Request::create('/v1/secure/apikey-header', 'GET');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("api key 'X-API-Key' is missing from the header");

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/apikey-header');
    }

    #[Test]
    public function inject_is_noop_on_oauth2_only_endpoint(): void
    {
        // oauth2-only endpoints are classified as Unsupported by the
        // validator (phase 1), so the requirement entry is skipped; injecting
        // bearer would be a lie. Validation must pass on its own (skipped).
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_inject_dummy_bearer'] = true;

        $request = Request::create('/v1/secure/oauth2-only', 'GET');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/oauth2-only');

        $this->assertArrayHasKey(
            'GET /v1/secure/oauth2-only',
            OpenApiCoverageTracker::getCovered()['petstore-3.0'] ?? [],
        );
    }

    #[Test]
    public function inject_in_and_requirement_still_surfaces_apikey_error(): void
    {
        // /v1/secure/and requires bearer AND apiKey in a single entry.
        // Injecting bearer alone cannot satisfy the entry, but the surfaced
        // error narrows to the apiKey (the actionable one) rather than both.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_inject_dummy_bearer'] = true;

        $request = Request::create('/v1/secure/and', 'GET');

        try {
            $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/and');
            $this->fail('Expected AssertionFailedError');
        } catch (AssertionFailedError $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString("api key 'X-API-Key' is missing", $message);
            $this->assertStringNotContainsString('Authorization header is missing', $message);
        }
    }

    #[Test]
    public function inject_flag_alone_without_auto_validate_request_does_nothing(): void
    {
        // Inject is a sub-feature of request validation — with validation
        // off, the inject flag must not run the validator as a side effect.
        // Proves the feature flags compose independently.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_validate_request'] = false;
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_inject_dummy_bearer'] = true;

        $request = Request::create('/v1/secure/bearer', 'GET');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/bearer');

        $this->assertArrayNotHasKey('petstore-3.0', OpenApiCoverageTracker::getCovered());
    }

    #[Test]
    public function inject_respects_existing_lowercase_authorization_header(): void
    {
        // Symfony's HeaderBag normalizes to lowercase. The inject must see
        // either case and not overwrite — regression guard for the
        // case-insensitive lookup used in shouldAutoInjectDummyBearer().
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_inject_dummy_bearer'] = true;

        $request = Request::create(
            '/v1/secure/bearer',
            'GET',
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer real-token.from-test'],
        );

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/bearer');

        // Valid bearer → covered. The dummy was not substituted.
        $this->assertArrayHasKey(
            'GET /v1/secure/bearer',
            OpenApiCoverageTracker::getCovered()['petstore-3.0'] ?? [],
        );
    }
}
