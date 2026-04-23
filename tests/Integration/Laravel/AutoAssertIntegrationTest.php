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
use Studio\OpenApiContractTesting\OpenApiSpec;
use Studio\OpenApiContractTesting\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\SkipOpenApi;

use function dirname;

/**
 * Exercises the full `$this->get()` / `$this->post()` → Laravel kernel →
 * `MakesHttpRequests::createTestResponse` → trait override pipeline under a
 * real Testbench app. Complements the unit tests (which call
 * `maybeAutoAssertOpenApiSchema` directly) by proving the framework-boundary
 * integration — the trait-provided `createTestResponse` actually wins over
 * the one Laravel merges in from `MakesHttpRequests`.
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
    public function auto_assert_true_validates_get_response(): void
    {
        config()->set('openapi-contract-testing.auto_assert', true);

        $response = $this->get('/v1/pets');
        $response->assertOk();

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('petstore-3.0', $covered);
        $this->assertArrayHasKey('GET /v1/pets', $covered['petstore-3.0']);
    }

    #[Test]
    public function auto_assert_true_validates_post_response(): void
    {
        // Pins POST specifically: the hook is verb-agnostic in theory, but a
        // regression that guards createTestResponse behind `method === 'GET'`
        // would otherwise go undetected.
        config()->set('openapi-contract-testing.auto_assert', true);

        $response = $this->postJson('/v1/pets', ['name' => 'Buddy']);
        $response->assertCreated();

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('POST /v1/pets', $covered['petstore-3.0'] ?? []);
    }

    #[Test]
    public function auto_assert_true_raises_on_schema_mismatch(): void
    {
        config()->set('openapi-contract-testing.auto_assert', true);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('OpenAPI schema validation failed');

        $this->get('/v1/pets?bad=1');
    }

    #[Test]
    public function auto_assert_false_does_not_validate(): void
    {
        config()->set('openapi-contract-testing.auto_assert', false);

        $response = $this->get('/v1/pets?bad=1');
        $response->assertOk();

        $this->assertArrayNotHasKey('petstore-3.0', OpenApiCoverageTracker::getCovered());
    }

    #[Test]
    public function auto_assert_not_set_defaults_to_skip(): void
    {
        $response = $this->get('/v1/pets?bad=1');
        $response->assertOk();

        $this->assertArrayNotHasKey('petstore-3.0', OpenApiCoverageTracker::getCovered());
    }

    #[Test]
    public function auto_assert_with_invalid_config_value_fails_loudly(): void
    {
        // Common user mistake: config value is a non-boolean-compatible value
        // (e.g. typo in a cast). The trait must surface this as a clear test
        // failure rather than silently treating it as "off".
        config()->set('openapi-contract-testing.auto_assert', 'definitely-not-a-bool');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('auto_assert must be a boolean');

        $this->get('/v1/pets');
    }

    #[Test]
    public function auto_assert_accepts_string_true_as_truthy(): void
    {
        // env('X') returns strings — `'auto_assert' => env('AUTO_ASSERT')`
        // would yield "true" (not boolean true). FILTER_VALIDATE_BOOLEAN
        // treats this as truthy, so the user isn't punished for common
        // env-var wiring.
        config()->set('openapi-contract-testing.auto_assert', 'true');

        $response = $this->get('/v1/pets');
        $response->assertOk();

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('GET /v1/pets', $covered['petstore-3.0'] ?? []);
    }

    #[Test]
    public function explicit_assert_after_auto_assert_is_idempotent(): void
    {
        config()->set('openapi-contract-testing.auto_assert', true);

        $response = $this->get('/v1/pets');
        $this->assertResponseMatchesOpenApiSchema($response);

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertCount(1, $covered['petstore-3.0'] ?? []);
    }

    #[Test]
    #[OpenApiSpec('petstore-3.1')]
    public function method_level_attribute_resolves_spec_for_auto_assert(): void
    {
        // default_spec is set to petstore-3.0 in setUp, but this method is
        // decorated with #[OpenApiSpec('petstore-3.1')] — auto-assert must
        // respect the attribute and record coverage under 3.1, not 3.0.
        config()->set('openapi-contract-testing.auto_assert', true);

        $response = $this->get('/v1/pets');
        $response->assertOk();

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('petstore-3.1', $covered);
        $this->assertArrayNotHasKey('petstore-3.0', $covered);
    }

    #[Test]
    #[SkipOpenApi(reason: 'intentional spec violation for test')]
    public function skip_open_api_attribute_opts_method_out_of_auto_assert(): void
    {
        // Would normally fail auto-assert because ?bad=1 returns {wrong_key:...}
        // which violates the spec. #[SkipOpenApi] must prevent that failure
        // AND stop coverage recording.
        config()->set('openapi-contract-testing.auto_assert', true);

        $response = $this->get('/v1/pets?bad=1');
        $response->assertOk();

        $this->assertArrayNotHasKey('petstore-3.0', OpenApiCoverageTracker::getCovered());
    }

    #[Test]
    #[SkipOpenApi]
    public function skip_open_api_attribute_opts_post_out_of_auto_assert(): void
    {
        // Guard against a regression that only checks skip on GET. The hook
        // is verb-agnostic in theory, but a bug that validated POST bodies
        // before consulting skip would only be caught here.
        config()->set('openapi-contract-testing.auto_assert', true);

        $response = $this->postJson('/v1/pets', ['name' => 'Buddy']);
        $response->assertCreated();

        $this->assertArrayNotHasKey('petstore-3.0', OpenApiCoverageTracker::getCovered());
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

        Route::post('/v1/pets', static fn() => response()->json(
            ['data' => ['id' => 42, 'name' => 'Buddy', 'tag' => null]],
            201,
        ));
    }
}
