<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\OpenApiResponseValidator;
use Studio\OpenApiContractTesting\OpenApiSpecLoader;

class ResponseValidationTest extends TestCase
{
    private OpenApiResponseValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        $this->validator = new OpenApiResponseValidator();
    }

    protected function tearDown(): void
    {
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function full_pipeline_v30_validate_and_track_coverage(): void
    {
        // Validate a valid response
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
        );
        $this->assertTrue($result->isValid());

        // Track coverage
        if ($result->matchedPath() !== null) {
            OpenApiCoverageTracker::record('petstore-3.0', 'GET', $result->matchedPath());
        }

        // Validate another endpoint
        $result2 = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            201,
            ['data' => ['id' => 2, 'name' => 'Whiskers', 'tag' => 'cat']],
        );
        $this->assertTrue($result2->isValid());

        if ($result2->matchedPath() !== null) {
            OpenApiCoverageTracker::record('petstore-3.0', 'POST', $result2->matchedPath());
        }

        // Check coverage
        $coverage = OpenApiCoverageTracker::computeCoverage('petstore-3.0');
        $this->assertSame(5, $coverage['total']);
        $this->assertSame(2, $coverage['coveredCount']);
        $this->assertContains('GET /v1/pets', $coverage['covered']);
        $this->assertContains('POST /v1/pets', $coverage['covered']);
        $this->assertContains('GET /v1/health', $coverage['uncovered']);
        $this->assertContains('DELETE /v1/pets/{petId}', $coverage['uncovered']);
        $this->assertContains('GET /v1/pets/{petId}', $coverage['uncovered']);
    }

    #[Test]
    public function full_pipeline_v31_validate_and_track_coverage(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/pets',
            200,
            ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
        );
        $this->assertTrue($result->isValid());

        if ($result->matchedPath() !== null) {
            OpenApiCoverageTracker::record('petstore-3.1', 'GET', $result->matchedPath());
        }

        $coverage = OpenApiCoverageTracker::computeCoverage('petstore-3.1');
        $this->assertSame(4, $coverage['total']);
        $this->assertSame(1, $coverage['coveredCount']);
    }

    #[Test]
    public function invalid_response_produces_descriptive_errors(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            ['wrong_key' => 'value'],
        );

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors());
        $this->assertNotEmpty($result->errorMessage());
    }
}
