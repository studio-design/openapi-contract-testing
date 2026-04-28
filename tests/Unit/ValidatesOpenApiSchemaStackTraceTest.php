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

use function is_string;
use function json_encode;
use function str_contains;
use function str_replace;

// Load namespace-level config() mock before the trait resolves the function call.
require_once __DIR__ . '/../Helpers/LaravelConfigMock.php';

class ValidatesOpenApiSchemaStackTraceTest extends TestCase
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
    public function failure_trace_excludes_validates_open_api_schema_frames(): void
    {
        $body = (string) json_encode(['wrong_key' => 'value'], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 200);

        try {
            $this->assertResponseMatchesOpenApiSchema(
                $response,
                HttpMethod::GET,
                '/v1/pets',
            );
            $this->fail('Expected AssertionFailedError was not thrown.');
        } catch (AssertionFailedError $e) {
            // The library frames must not appear in the trace shown to the user.
            foreach ($e->getTrace() as $index => $frame) {
                $file = $frame['file'] ?? null;
                if (!is_string($file)) {
                    continue;
                }
                $normalized = str_replace('\\', '/', $file);
                $this->assertFalse(
                    str_contains($normalized, '/openapi-contract-testing/src/Laravel/'),
                    "Frame #{$index} should not be inside src/Laravel/ but was: {$file}",
                );
            }

            // Header line ("in /path:NN") must point at user code, not the trait.
            $exceptionFile = str_replace('\\', '/', $e->getFile());
            $this->assertFalse(
                str_contains($exceptionFile, '/openapi-contract-testing/src/Laravel/'),
                "Exception getFile() should not point inside the library: {$exceptionFile}",
            );

            // Message is unchanged — we only rewrite trace metadata.
            $this->assertStringContainsString(
                'OpenAPI schema validation failed',
                $e->getMessage(),
            );
        }
    }

    #[Test]
    public function user_test_frame_survives_filtering(): void
    {
        $body = (string) json_encode(['wrong_key' => 'value'], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 200);

        try {
            $this->assertResponseMatchesOpenApiSchema(
                $response,
                HttpMethod::GET,
                '/v1/pets',
            );
            $this->fail('Expected AssertionFailedError was not thrown.');
        } catch (AssertionFailedError $e) {
            $hasUserFrame = false;
            foreach ($e->getTrace() as $frame) {
                $file = $frame['file'] ?? null;
                if (!is_string($file)) {
                    continue;
                }
                if (str_contains(str_replace('\\', '/', $file), 'ValidatesOpenApiSchemaStackTraceTest.php')) {
                    $hasUserFrame = true;

                    break;
                }
            }
            $this->assertTrue(
                $hasUserFrame,
                'Expected the user test frame to survive filtering — only library and Laravel testing-concern frames should be dropped.',
            );
        }
    }
}
