<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use const JSON_THROW_ON_ERROR;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\HttpMethod;
use Studio\OpenApiContractTesting\Laravel\ValidatesOpenApiSchema;
use Studio\OpenApiContractTesting\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\OpenApiSpecLoader;

use function json_encode;

class ValidatesOpenApiSchemaTest extends TestCase
{
    use ValidatesOpenApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        OpenApiCoverageTracker::reset();
        $this->openApiSpec = 'petstore-3.0';
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function empty_body_204_response_passes_validation(): void
    {
        $response = $this->makeTestResponse('', 204);

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::DELETE,
            '/v1/pets/123',
        );
    }

    #[Test]
    public function empty_body_200_without_content_schema_passes_validation(): void
    {
        $response = $this->makeTestResponse('', 200);

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::GET,
            '/v1/health',
        );
    }

    #[Test]
    public function json_body_response_passes_validation(): void
    {
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

    #[Test]
    public function invalid_json_body_fails_validation(): void
    {
        $body = (string) json_encode(['wrong_key' => 'value'], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 200);

        $this->expectException(AssertionFailedError::class);

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::GET,
            '/v1/pets',
        );
    }

    #[Test]
    public function empty_body_fails_when_spec_requires_json_schema(): void
    {
        $response = $this->makeTestResponse('', 200);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Response body is empty');

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::GET,
            '/v1/pets',
        );
    }

    #[Test]
    public function successful_validation_records_coverage(): void
    {
        $body = (string) json_encode(
            ['data' => [['id' => 1, 'name' => 'Fido']]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::GET,
            '/v1/pets',
        );

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('petstore-3.0', $covered);
        $this->assertArrayHasKey('GET /v1/pets', $covered['petstore-3.0']);
    }

    private function makeTestResponse(string $content, int $statusCode): TestResponse
    {
        $baseResponse = new class ($content, $statusCode) {
            public function __construct(
                private readonly string $content,
                private readonly int $statusCode,
            ) {}

            public function getContent(): string
            {
                return $this->content;
            }

            public function getStatusCode(): int
            {
                return $this->statusCode;
            }
        };

        return new TestResponse($baseResponse);
    }
}
