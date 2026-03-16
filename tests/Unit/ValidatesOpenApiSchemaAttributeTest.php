<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\HttpMethod;
use Studio\OpenApiContractTesting\Laravel\OpenApiSpec;
use Studio\OpenApiContractTesting\Laravel\ValidatesOpenApiSchema;
use Studio\OpenApiContractTesting\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\Tests\Helpers\CreatesTestResponse;

use function json_encode;

#[OpenApiSpec('petstore-3.0')]
class ValidatesOpenApiSchemaAttributeTest extends TestCase
{
    use CreatesTestResponse;
    use ValidatesOpenApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        OpenApiCoverageTracker::reset();
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function class_level_attribute_resolves_spec(): void
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
    #[OpenApiSpec('petstore-3.1')]
    public function method_level_attribute_overrides_class_level(): void
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

        // Verify coverage was recorded under the method-level spec name
        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('petstore-3.1', $covered);
    }
}
