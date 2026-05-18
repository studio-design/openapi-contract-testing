<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Integration\Symfony;

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
use Symfony\Component\HttpKernel\HttpKernelBrowser;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Exercises {@see OpenApiAssertions::assertClientMatchesOpenApiSchema()}
 * against a real {@see HttpKernelBrowser} round-trip. The kernel is a tiny
 * stand-in (no framework bundle / container) that echoes a fixed response,
 * so the test confirms the client wiring — `getRequest()` / `getResponse()`
 * extraction — without booting a full Symfony application.
 */
#[OpenApiSpec('petstore-3.0')]
final class OpenApiAssertionsClientTest extends TestCase
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
    public function client_request_and_response_pass_validation(): void
    {
        $client = $this->clientReturning(
            new JsonResponse(['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]]),
        );
        $client->request('GET', '/v1/pets');

        $this->assertClientMatchesOpenApiSchema($client);

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('petstore-3.0', $covered);
        $this->assertArrayHasKey('GET /v1/pets', $covered['petstore-3.0']);
    }

    #[Test]
    public function client_off_schema_response_fails_validation(): void
    {
        $client = $this->clientReturning(new JsonResponse(['wrong_key' => 'value']));
        $client->request('GET', '/v1/pets');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('OpenAPI schema validation failed for GET /v1/pets');

        $this->assertClientMatchesOpenApiSchema($client);
    }

    private function clientReturning(Response $response): HttpKernelBrowser
    {
        $kernel = new class ($response) implements HttpKernelInterface {
            public function __construct(private readonly Response $response) {}

            public function handle(
                Request $request,
                int $type = self::MAIN_REQUEST,
                bool $catch = true,
            ): Response {
                return $this->response;
            }
        };

        return new HttpKernelBrowser($kernel);
    }
}
