<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Symfony;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Attribute\OpenApiSpec;
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\Symfony\OpenApiAssertions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[OpenApiSpec('petstore-3.0')]
final class OpenApiAssertionsTest extends TestCase
{
    use OpenApiAssertions;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
        OpenApiCoverageTracker::reset();
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function valid_response_passes_validation(): void
    {
        $request = Request::create('/v1/pets', 'GET');
        $response = new JsonResponse(['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]]);

        $this->assertResponseMatchesOpenApiSchema($request, $response);
    }

    #[Test]
    public function invalid_response_fails_validation(): void
    {
        $request = Request::create('/v1/pets', 'GET');
        $response = new JsonResponse(['wrong_key' => 'value']);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('spec: petstore-3.0');

        $this->assertResponseMatchesOpenApiSchema($request, $response);
    }

    #[Test]
    public function empty_204_response_passes_validation(): void
    {
        $request = Request::create('/v1/pets/123', 'DELETE');
        $response = new Response('', 204);

        $this->assertResponseMatchesOpenApiSchema($request, $response);
    }

    #[Test]
    public function skipped_5xx_response_passes_validation(): void
    {
        $request = Request::create('/v1/pets', 'GET');
        $response = new JsonResponse(['anything' => true], 500);

        // 5xx matches the default skip pattern: body validation is skipped and
        // the result stays valid even though the body is off-schema.
        $this->assertResponseMatchesOpenApiSchema($request, $response);
    }

    #[Test]
    public function extra_skip_response_code_skips_body_validation(): void
    {
        $request = Request::create('/v1/pets', 'POST');
        $response = new JsonResponse(['wrong_key' => 'value'], 409);

        $this->assertResponseMatchesOpenApiSchema($request, $response, ['409']);
    }

    #[Test]
    public function successful_validation_records_response_coverage(): void
    {
        $request = Request::create('/v1/pets', 'GET');
        $response = new JsonResponse(['data' => [['id' => 1, 'name' => 'Fido']]]);

        $this->assertResponseMatchesOpenApiSchema($request, $response);

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('petstore-3.0', $covered);
        $this->assertArrayHasKey('GET /v1/pets', $covered['petstore-3.0']);
    }

    #[Test]
    public function valid_request_passes_validation(): void
    {
        $request = Request::create('/v1/pets', 'GET');

        $this->assertRequestMatchesOpenApiSchema($request);
    }

    #[Test]
    public function invalid_request_body_fails_validation(): void
    {
        $request = Request::create(
            '/v1/pets',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{}',
        );

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('OpenAPI request validation failed for POST /v1/pets');

        $this->assertRequestMatchesOpenApiSchema($request);
    }

    #[Test]
    public function unsupported_http_method_fails_with_clear_message(): void
    {
        $request = Request::create('/v1/pets', 'TRACE');
        $response = new JsonResponse(['data' => []]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('unsupported HTTP method');

        $this->assertResponseMatchesOpenApiSchema($request, $response);
    }
}
