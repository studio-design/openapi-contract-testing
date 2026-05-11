<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;

/*
|--------------------------------------------------------------------------
| Pest expectation integration
|--------------------------------------------------------------------------
|
| Exercises the Pest plugin's `toMatchOpenApiResponseSchema` /
| `toMatchOpenApiRequestSchema` end-to-end through Orchestra Testbench
| (configured by `tests/Helpers/PestLaravelTestCase` and wired in
| `tests/Pest.php`). Each `it(...)` boots a fresh Laravel app and resets
| the coverage tracker, so test ordering is irrelevant.
|
| The companion `PluginLoadsTest.php` proves only that the autoload
| boundary is wired. This file proves the dispatch actually runs the
| existing OpenApiResponseValidator / OpenApiRequestValidator pipeline.
*/

it('validates a matching response against the default spec', function (): void {
    $response = $this->get('/v1/pets');
    $response->assertOk();

    expect($response)->toMatchOpenApiResponseSchema();

    $covered = OpenApiCoverageTracker::getCovered();
    expect($covered)
        ->toHaveKey('petstore-3.0')
        ->and($covered['petstore-3.0'])
        ->toHaveKey('GET /v1/pets');
});

it('fails when the response does not match the schema', function (): void {
    $response = $this->get('/v1/pets?bad=1');

    expect(static fn () => expect($response)->toMatchOpenApiResponseSchema())
        ->toThrow(AssertionFailedError::class, 'OpenAPI schema validation failed');
});

it('honours the spec: argument as a per-call override', function (): void {
    $response = $this->get('/v1/pets');
    $response->assertOk();

    expect($response)->toMatchOpenApiResponseSchema(spec: 'petstore-3.1');

    $covered = OpenApiCoverageTracker::getCovered();
    expect($covered)->toHaveKey('petstore-3.1')
        ->and($covered)->not->toHaveKey('petstore-3.0');
});

it('honours the skipResponseCodes argument for per-call skip', function (): void {
    // /v1/health returns 503; the spec only documents 200, so a strict
    // assertion would fail with "Status code 503 not defined". Passing
    // skipResponseCodes turns that into a skip rather than a failure.
    $response = $this->get('/v1/health');

    expect($response)->toMatchOpenApiResponseSchema(skipResponseCodes: ['503']);
});

it('validates a matching request via toMatchOpenApiRequestSchema', function (): void {
    $this->postJson('/v1/pets', ['name' => 'Buddy']);

    /** @var \Symfony\Component\HttpFoundation\Request $request */
    $request = app('request');

    expect($request)->toMatchOpenApiRequestSchema();

    $covered = OpenApiCoverageTracker::getCovered();
    expect($covered)
        ->toHaveKey('petstore-3.0')
        ->and($covered['petstore-3.0'])
        ->toHaveKey('POST /v1/pets');
});

it('does not double-record coverage when explicit assert follows auto-assert', function (): void {
    config()->set('openapi-contract-testing.auto_assert', true);

    $response = $this->get('/v1/pets');

    // auto-assert already validated this response and recorded its hit.
    // The explicit expect() is a WeakMap-deduped no-op — coverage hits
    // for GET /v1/pets / 200 must remain at 1.
    expect($response)->toMatchOpenApiResponseSchema();

    $coverage = OpenApiCoverageTracker::computeCoverage('petstore-3.0');
    $endpoint = null;
    foreach ($coverage['endpoints'] as $candidate) {
        if ($candidate['endpoint'] === 'GET /v1/pets') {
            $endpoint = $candidate;

            break;
        }
    }
    expect($endpoint)->not->toBeNull();
    $hits = $endpoint['responses'][0]['hits'] ?? -1;
    expect($hits)->toBe(1);
});

it('chains expectations after the schema match', function (): void {
    $response = $this->get('/v1/pets');

    // Returning $this from the closure (verified at registration in
    // PluginLoadsTest) lets the result chain into other expectations.
    expect($response)
        ->toMatchOpenApiResponseSchema()
        ->toBeInstanceOf(\Illuminate\Testing\TestResponse::class);
});
