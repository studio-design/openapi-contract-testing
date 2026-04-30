<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Fuzz;

use InvalidArgumentException;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Fuzz\ExplorationCases;
use Studio\OpenApiContractTesting\Fuzz\ExploredCase;
use Studio\OpenApiContractTesting\Fuzz\OpenApiEndpointExplorer;
use Studio\OpenApiContractTesting\OpenApiSpecLoader;

use function json_decode;
use function json_encode;

class OpenApiEndpointExplorerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function rejects_zero_cases(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OpenApiEndpointExplorer::explore('petstore-3.0', 'POST', '/v1/pets', cases: 0);
    }

    #[Test]
    public function rejects_unknown_path(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not declared');

        OpenApiEndpointExplorer::explore('petstore-3.0', 'POST', '/does/not/exist', cases: 1);
    }

    #[Test]
    public function rejects_method_not_on_path(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Operation');

        // POST /v1/pets/{petId} is not declared in petstore-3.0
        OpenApiEndpointExplorer::explore('petstore-3.0', 'POST', '/v1/pets/{petId}', cases: 1);
    }

    #[Test]
    public function returns_exploration_cases_collection(): void
    {
        $cases = OpenApiEndpointExplorer::explore('petstore-3.0', 'POST', '/v1/pets', cases: 3, seed: 1);

        $this->assertInstanceOf(ExplorationCases::class, $cases);
        $this->assertCount(3, $cases);
        foreach ($cases as $case) {
            $this->assertInstanceOf(ExploredCase::class, $case);
            $this->assertSame('POST', $case->method);
            $this->assertSame('/v1/pets', $case->matchedPath);
        }
    }

    #[Test]
    public function generates_request_body_conforming_to_spec(): void
    {
        // POST /v1/pets requires { name: string, tag?: string|null }
        $cases = OpenApiEndpointExplorer::explore('petstore-3.0', 'POST', '/v1/pets', cases: 5, seed: 7);

        $bodySchema = (object) [
            'type' => 'object',
            'required' => ['name'],
            'properties' => (object) [
                'name' => (object) ['type' => 'string'],
                'tag' => (object) ['type' => ['string', 'null']],
            ],
        ];
        $validator = new Validator();

        foreach ($cases as $case) {
            $this->assertIsArray($case->body, 'POST /v1/pets must produce an object body');
            $bodyJson = json_decode((string) json_encode($case->body));
            $result = $validator->validate($bodyJson, $bodySchema);
            $this->assertTrue($result->isValid(), 'generated body must satisfy spec schema');
        }
    }

    #[Test]
    public function generates_query_parameter_values(): void
    {
        // POST /v1/pets has a `dryRun` boolean query parameter.
        $cases = OpenApiEndpointExplorer::explore('petstore-3.0', 'POST', '/v1/pets', cases: 4, seed: 1);

        $sawValue = false;
        foreach ($cases as $case) {
            if (isset($case->query['dryRun'])) {
                $this->assertIsBool($case->query['dryRun']);
                $sawValue = true;
            }
        }
        $this->assertTrue($sawValue, 'at least one case should generate the dryRun query param');
    }

    #[Test]
    public function generates_path_parameters_for_template_form(): void
    {
        // /v1/pets/{petId} has a path param `petId` of type integer.
        $cases = OpenApiEndpointExplorer::explore('petstore-3.0', 'GET', '/v1/pets/{petId}', cases: 2, seed: 1);

        foreach ($cases as $case) {
            $this->assertSame('/v1/pets/{petId}', $case->matchedPath);
            $this->assertArrayHasKey('petId', $case->pathParams);
        }
    }

    #[Test]
    public function resolves_concrete_uri_to_spec_template(): void
    {
        $cases = OpenApiEndpointExplorer::explore('petstore-3.0', 'GET', '/v1/pets/123', cases: 1, seed: 1);

        $this->assertCount(1, $cases);
        foreach ($cases as $case) {
            $this->assertSame('/v1/pets/{petId}', $case->matchedPath);
        }
    }

    #[Test]
    public function null_body_when_operation_has_no_request_body(): void
    {
        // GET /v1/pets has no requestBody.
        $cases = OpenApiEndpointExplorer::explore('petstore-3.0', 'GET', '/v1/pets', cases: 2, seed: 1);

        foreach ($cases as $case) {
            $this->assertNull($case->body);
        }
    }

    #[Test]
    public function honors_strip_prefixes_for_concrete_uri(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs', stripPrefixes: ['/api']);

        $cases = OpenApiEndpointExplorer::explore('petstore-3.0', 'GET', '/api/v1/pets/123', cases: 1, seed: 1);

        $this->assertCount(1, $cases);
        foreach ($cases as $case) {
            $this->assertSame('/v1/pets/{petId}', $case->matchedPath);
        }
    }
}
