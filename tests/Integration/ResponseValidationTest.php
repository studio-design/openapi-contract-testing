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
        $this->assertSame(23, $coverage['total']);
        $this->assertSame(2, $coverage['coveredCount']);
        $this->assertContains('GET /v1/pets', $coverage['covered']);
        $this->assertContains('POST /v1/pets', $coverage['covered']);
        $this->assertContains('GET /v1/health', $coverage['uncovered']);
        $this->assertContains('GET /v1/logout', $coverage['uncovered']);
        $this->assertContains('GET /v1/pets/search', $coverage['uncovered']);
        $this->assertContains('DELETE /v1/pets/{petId}', $coverage['uncovered']);
        $this->assertContains('GET /v1/pets/{petId}', $coverage['uncovered']);
        $this->assertContains('PATCH /v1/pets/{petId}', $coverage['uncovered']);
        $this->assertContains('PUT /v1/pets/{petId}', $coverage['uncovered']);
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
        $this->assertSame(19, $coverage['total']);
        $this->assertSame(1, $coverage['coveredCount']);
    }

    #[Test]
    public function non_json_endpoint_skips_validation_and_records_coverage(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/logout',
            200,
            '<html><body>Logged out</body></html>',
        );
        $this->assertTrue($result->isValid());

        if ($result->matchedPath() !== null) {
            OpenApiCoverageTracker::record('petstore-3.0', 'GET', $result->matchedPath());
        }

        $coverage = OpenApiCoverageTracker::computeCoverage('petstore-3.0');
        $this->assertSame(1, $coverage['coveredCount']);
        $this->assertContains('GET /v1/logout', $coverage['covered']);
    }

    #[Test]
    public function content_negotiation_non_json_response_succeeds_and_records_coverage(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            409,
            null,
            'text/html',
        );
        $this->assertTrue($result->isValid());

        if ($result->matchedPath() !== null) {
            OpenApiCoverageTracker::record('petstore-3.0', 'POST', $result->matchedPath());
        }

        $coverage = OpenApiCoverageTracker::computeCoverage('petstore-3.0');
        $this->assertSame(1, $coverage['coveredCount']);
        $this->assertContains('POST /v1/pets', $coverage['covered']);
    }

    #[Test]
    public function response_500_records_endpoint_as_skipped_only(): void
    {
        // 500 matches the default skip pattern; the validator returns a
        // skipped result, and the tracker call mirrors what the Laravel
        // trait does at src/Laravel/ValidatesOpenApiSchema.php:477 —
        // schemaValidated derives from !isSkipped().
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            500,
            ['anything' => 'goes'],
        );
        // Record before asserting so the `schemaValidated: !isSkipped()`
        // expression mirrors the Laravel trait's dynamic form at
        // src/Laravel/ValidatesOpenApiSchema.php:477 — asserting first would
        // let phpstan narrow isSkipped() to a constant and flag the negation
        // as always-false.
        if ($result->matchedPath() !== null) {
            OpenApiCoverageTracker::record(
                'petstore-3.0',
                'GET',
                $result->matchedPath(),
                schemaValidated: !$result->isSkipped(),
            );
        }

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isSkipped());

        $coverage = OpenApiCoverageTracker::computeCoverage('petstore-3.0');
        $this->assertContains('GET /v1/pets', $coverage['covered']);
        $this->assertSame(['GET /v1/pets'], $coverage['skippedOnly']);
        $this->assertSame(1, $coverage['skippedOnlyCount']);
    }

    #[Test]
    public function response_200_then_500_keeps_endpoint_validated(): void
    {
        $ok = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
        );
        OpenApiCoverageTracker::record(
            'petstore-3.0',
            'GET',
            $ok->matchedPath() ?? '/v1/pets',
            schemaValidated: !$ok->isSkipped(),
        );

        $skip = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            500,
            ['anything' => 'goes'],
        );
        OpenApiCoverageTracker::record(
            'petstore-3.0',
            'GET',
            $skip->matchedPath() ?? '/v1/pets',
            schemaValidated: !$skip->isSkipped(),
        );

        $coverage = OpenApiCoverageTracker::computeCoverage('petstore-3.0');
        $this->assertContains('GET /v1/pets', $coverage['covered']);
        $this->assertSame([], $coverage['skippedOnly']);
        $this->assertSame(0, $coverage['skippedOnlyCount']);
    }

    #[Test]
    public function response_500_then_200_keeps_endpoint_validated(): void
    {
        // Reverse order of the previous test — the monotonic "validated"
        // flag means ordering does not matter.
        $skip = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            500,
            ['anything' => 'goes'],
        );
        OpenApiCoverageTracker::record(
            'petstore-3.0',
            'GET',
            $skip->matchedPath() ?? '/v1/pets',
            schemaValidated: !$skip->isSkipped(),
        );

        $ok = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
        );
        OpenApiCoverageTracker::record(
            'petstore-3.0',
            'GET',
            $ok->matchedPath() ?? '/v1/pets',
            schemaValidated: !$ok->isSkipped(),
        );

        $coverage = OpenApiCoverageTracker::computeCoverage('petstore-3.0');
        $this->assertContains('GET /v1/pets', $coverage['covered']);
        $this->assertSame([], $coverage['skippedOnly']);
        $this->assertSame(0, $coverage['skippedOnlyCount']);
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
