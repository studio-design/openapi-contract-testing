<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Integration\Laravel;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use Studio\OpenApiContractTesting\Laravel\OpenApiContractTestingServiceProvider;
use Studio\OpenApiContractTesting\Laravel\ValidatesOpenApiSchema;
use Studio\OpenApiContractTesting\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\OpenApiSpecLoader;

use function dirname;

/**
 * Full Laravel integration test proving that applying the
 * ValidatesOpenApiSchema trait with auto_assert=true is sufficient for
 * $this->get() / post() / etc. to trigger OpenAPI validation — no explicit
 * assertResponseMatchesOpenApiSchema() call required.
 */
class AutoAssertIntegrationTest extends TestCase
{
    use ValidatesOpenApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(dirname(__DIR__, 2) . '/fixtures/specs');
        OpenApiCoverageTracker::reset();
        config()->set('openapi-contract-testing.default_spec', 'petstore-3.0');
    }

    protected function tearDown(): void
    {
        self::resetValidatorCache();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function auto_assert_true_validates_http_response_without_explicit_call(): void
    {
        config()->set('openapi-contract-testing.auto_assert', true);

        // No explicit assertResponseMatchesOpenApiSchema call — trait alone
        // must trigger validation when auto_assert=true.
        $response = $this->get('/v1/pets');

        $response->assertOk();

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('petstore-3.0', $covered);
        $this->assertArrayHasKey('GET /v1/pets', $covered['petstore-3.0']);
    }

    #[Test]
    public function auto_assert_true_raises_assertion_error_on_schema_mismatch(): void
    {
        config()->set('openapi-contract-testing.auto_assert', true);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('OpenAPI schema validation failed');

        // Auto-assert must surface the mismatch without an explicit assert.
        $this->get('/v1/pets?bad=1');
    }

    #[Test]
    public function auto_assert_false_does_not_validate_automatically(): void
    {
        config()->set('openapi-contract-testing.auto_assert', false);

        // Invalid body but auto_assert=false — no exception, no coverage.
        $response = $this->get('/v1/pets?bad=1');

        $response->assertOk();

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayNotHasKey('petstore-3.0', $covered);
    }

    #[Test]
    public function auto_assert_not_set_defaults_to_skip(): void
    {
        // auto_assert not explicitly set — config merge default (false) applies.
        $response = $this->get('/v1/pets?bad=1');

        $response->assertOk();

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayNotHasKey('petstore-3.0', $covered);
    }

    #[Test]
    public function explicit_and_auto_assert_are_idempotent(): void
    {
        config()->set('openapi-contract-testing.auto_assert', true);

        // Auto-assert runs during $this->get(). A subsequent explicit call on
        // the same response must not re-run validation nor re-record coverage.
        $response = $this->get('/v1/pets');
        $this->assertResponseMatchesOpenApiSchema($response);

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertCount(1, $covered['petstore-3.0'] ?? []);
    }

    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [OpenApiContractTestingServiceProvider::class];
    }

    protected function defineRoutes($router): void
    {
        Route::get('/v1/pets', static function () {
            $bad = request()->query('bad') === '1';

            return response()->json(
                $bad
                    ? ['wrong_key' => 'value']
                    : ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
            );
        });
    }
}
