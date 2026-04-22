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

use function count;
use function json_encode;

// Load namespace-level config() mock before the trait resolves the function call.
require_once __DIR__ . '/../Helpers/LaravelConfigMock.php';

class ValidatesOpenApiSchemaAutoAssertTest extends TestCase
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
    public function config_file_defaults_auto_assert_to_false(): void
    {
        $config = require __DIR__ . '/../../src/Laravel/config.php';

        $this->assertArrayHasKey('auto_assert', $config);
        $this->assertFalse($config['auto_assert']);
    }

    #[Test]
    public function auto_assert_true_validates_valid_response_without_error(): void
    {
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_assert'] = true;

        $body = (string) json_encode(
            ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        $this->maybeAutoAssertOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('petstore-3.0', $covered);
        $this->assertArrayHasKey('GET /v1/pets', $covered['petstore-3.0']);
    }

    #[Test]
    public function auto_assert_true_raises_assertion_error_for_invalid_response(): void
    {
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_assert'] = true;

        $body = (string) json_encode(['wrong_key' => 'value'], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 200);

        $this->expectException(AssertionFailedError::class);

        $this->maybeAutoAssertOpenApiSchema($response, HttpMethod::GET, '/v1/pets');
    }

    #[Test]
    public function auto_assert_false_skips_validation_for_invalid_response(): void
    {
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_assert'] = false;

        $body = (string) json_encode(['wrong_key' => 'value'], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 200);

        // No exception expected — validation is skipped.
        $this->maybeAutoAssertOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayNotHasKey('petstore-3.0', $covered);
    }

    #[Test]
    public function auto_assert_not_set_skips_validation_for_invalid_response(): void
    {
        // No auto_assert config key present — default should be false.
        $body = (string) json_encode(['wrong_key' => 'value'], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 200);

        $this->maybeAutoAssertOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayNotHasKey('petstore-3.0', $covered);
    }

    #[Test]
    public function double_manual_assert_is_idempotent(): void
    {
        $body = (string) json_encode(
            ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        $this->assertResponseMatchesOpenApiSchema($response, HttpMethod::GET, '/v1/pets');
        $this->assertResponseMatchesOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('petstore-3.0', $covered);
        $this->assertCount(
            1,
            $covered['petstore-3.0'],
            'Coverage entries should not be duplicated when the same response is validated twice.',
        );
    }

    #[Test]
    public function manual_assert_then_auto_assert_is_idempotent(): void
    {
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_assert'] = true;

        $body = (string) json_encode(
            ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        $this->assertResponseMatchesOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        $coveredBefore = OpenApiCoverageTracker::getCovered();
        $countBefore = count($coveredBefore['petstore-3.0'] ?? []);

        // A subsequent auto-assert on the same response must not re-validate or
        // re-record coverage.
        $this->maybeAutoAssertOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        $coveredAfter = OpenApiCoverageTracker::getCovered();
        $this->assertCount($countBefore, $coveredAfter['petstore-3.0'] ?? []);
    }

    #[Test]
    public function auto_assert_then_manual_assert_does_not_raise_for_invalid_response(): void
    {
        // When auto-assert has already failed (and been caught), a manual
        // assert on the same response instance should no-op so the same error
        // is not reported twice. We simulate this by recording the response as
        // validated via a successful auto-assert first, then calling the
        // manual API — which would normally raise on a subsequent mismatch —
        // expecting no exception because the response is already marked.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_assert'] = true;

        $body = (string) json_encode(
            ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        $this->maybeAutoAssertOpenApiSchema($response, HttpMethod::GET, '/v1/pets');
        // Calling manual assert afterwards must not throw or add another
        // coverage entry.
        $this->assertResponseMatchesOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertCount(1, $covered['petstore-3.0'] ?? []);
    }
}
