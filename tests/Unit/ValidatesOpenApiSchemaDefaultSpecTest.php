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

use function array_filter;
use function count;
use function explode;
use function json_encode;
use function str_starts_with;
use function trim;

// Load namespace-level config() mock before the trait resolves the function call.
require_once __DIR__ . '/../Helpers/LaravelConfigMock.php';

class ValidatesOpenApiSchemaDefaultSpecTest extends TestCase
{
    use CreatesTestResponse;
    use ValidatesOpenApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        OpenApiCoverageTracker::reset();
        $GLOBALS['__openapi_testing_config'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__openapi_testing_config']);
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function default_open_api_spec_returns_empty_string_when_config_not_set(): void
    {
        $this->assertSame('', $this->openApiSpec());
    }

    #[Test]
    public function default_open_api_spec_returns_config_value(): void
    {
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.default_spec'] = 'petstore-3.0';

        $this->assertSame('petstore-3.0', $this->openApiSpec());
    }

    #[Test]
    public function null_config_value_returns_empty_string(): void
    {
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.default_spec'] = null;

        $this->assertSame('', $this->openApiSpec());
    }

    #[Test]
    public function empty_config_default_spec_fails_with_clear_message(): void
    {
        $response = $this->makeTestResponse('{}', 200);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage(
            'openApiSpec() must return a non-empty spec name, but an empty string was returned. '
            . 'Either override openApiSpec() in your test class, or set the "default_spec" key '
            . 'in config/openapi-contract-testing.php.',
        );

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::GET,
            '/v1/pets',
        );
    }

    #[Test]
    public function configured_default_spec_validates_successfully(): void
    {
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.default_spec'] = 'petstore-3.0';

        $body = (string) json_encode(
            ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::GET,
            '/v1/pets',
        );
    }

    // ========================================
    // max_errors config tests
    // ========================================

    #[Test]
    public function max_errors_config_limits_reported_errors(): void
    {
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.default_spec'] = 'petstore-3.0';
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.max_errors'] = 1;

        $body = (string) json_encode(
            ['data' => [['id' => 'bad', 'name' => 123], ['id' => 'bad', 'name' => 456]]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        try {
            $this->assertResponseMatchesOpenApiSchema(
                $response,
                HttpMethod::GET,
                '/v1/pets',
            );
            $this->fail('Expected AssertionFailedError was not thrown.');
        } catch (AssertionFailedError $e) {
            // With max_errors=1, the error message should contain exactly one schema error line.
            // The error format is "[path] message", so count lines starting with "[".
            $lines = explode("\n", $e->getMessage());
            $errorLines = array_filter($lines, static fn(string $line) => str_starts_with(trim($line), '['));
            $this->assertCount(1, $errorLines);
        }
    }

    #[Test]
    public function string_numeric_max_errors_config_is_cast_to_int(): void
    {
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.default_spec'] = 'petstore-3.0';
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.max_errors'] = '1';

        $body = (string) json_encode(
            ['data' => [['id' => 'bad', 'name' => 123], ['id' => 'bad', 'name' => 456]]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        try {
            $this->assertResponseMatchesOpenApiSchema(
                $response,
                HttpMethod::GET,
                '/v1/pets',
            );
            $this->fail('Expected AssertionFailedError was not thrown.');
        } catch (AssertionFailedError $e) {
            $lines = explode("\n", $e->getMessage());
            $errorLines = array_filter($lines, static fn(string $line) => str_starts_with(trim($line), '['));
            $this->assertCount(1, $errorLines);
        }
    }

    #[Test]
    public function non_numeric_max_errors_config_falls_back_to_default(): void
    {
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.default_spec'] = 'petstore-3.0';
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.max_errors'] = 'not-a-number';

        $body = (string) json_encode(
            ['data' => [['id' => 'bad', 'name' => 123], ['id' => 'bad', 'name' => 456]]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        try {
            $this->assertResponseMatchesOpenApiSchema(
                $response,
                HttpMethod::GET,
                '/v1/pets',
            );
            $this->fail('Expected AssertionFailedError was not thrown.');
        } catch (AssertionFailedError $e) {
            // Falls back to default of 20, so multiple errors should be reported
            $lines = explode("\n", $e->getMessage());
            $errorLines = array_filter($lines, static fn(string $line) => str_starts_with(trim($line), '['));
            $this->assertGreaterThan(1, count($errorLines));
        }
    }
}
