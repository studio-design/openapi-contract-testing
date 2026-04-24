<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\OpenApiSpecLoader;

class OpenApiCoverageTrackerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
    }

    protected function tearDown(): void
    {
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function record_stores_covered_endpoint(): void
    {
        OpenApiCoverageTracker::record('petstore-3.0', 'GET', '/v1/pets');

        $covered = OpenApiCoverageTracker::getCovered();

        $this->assertArrayHasKey('petstore-3.0', $covered);
        $this->assertArrayHasKey('GET /v1/pets', $covered['petstore-3.0']);
    }

    #[Test]
    public function record_uppercases_method(): void
    {
        OpenApiCoverageTracker::record('petstore-3.0', 'get', '/v1/pets');

        $covered = OpenApiCoverageTracker::getCovered();

        $this->assertArrayHasKey('GET /v1/pets', $covered['petstore-3.0']);
    }

    #[Test]
    public function record_deduplicates(): void
    {
        OpenApiCoverageTracker::record('petstore-3.0', 'GET', '/v1/pets');
        OpenApiCoverageTracker::record('petstore-3.0', 'GET', '/v1/pets');

        $covered = OpenApiCoverageTracker::getCovered();

        $this->assertCount(1, $covered['petstore-3.0']);
    }

    #[Test]
    public function compute_coverage_returns_correct_stats(): void
    {
        OpenApiCoverageTracker::record('petstore-3.0', 'GET', '/v1/pets');
        OpenApiCoverageTracker::record('petstore-3.0', 'POST', '/v1/pets');

        $result = OpenApiCoverageTracker::computeCoverage('petstore-3.0');

        // See tests/fixtures/specs/petstore-3.0.json for the full endpoint list
        $this->assertSame(23, $result['total']);
        $this->assertSame(2, $result['coveredCount']);
        $this->assertCount(2, $result['covered']);
        $this->assertCount(21, $result['uncovered']);
    }

    #[Test]
    public function compute_coverage_with_no_coverage(): void
    {
        $result = OpenApiCoverageTracker::computeCoverage('petstore-3.0');

        $this->assertSame(23, $result['total']);
        $this->assertSame(0, $result['coveredCount']);
        $this->assertCount(0, $result['covered']);
        $this->assertCount(23, $result['uncovered']);
    }

    #[Test]
    public function reset_clears_all_coverage(): void
    {
        OpenApiCoverageTracker::record('petstore-3.0', 'GET', '/v1/pets');

        OpenApiCoverageTracker::reset();

        $this->assertSame([], OpenApiCoverageTracker::getCovered());
    }

    #[Test]
    public function record_defaults_to_schema_validated_true(): void
    {
        OpenApiCoverageTracker::record('petstore-3.0', 'GET', '/v1/pets');

        $result = OpenApiCoverageTracker::computeCoverage('petstore-3.0');

        $this->assertContains('GET /v1/pets', $result['covered']);
        $this->assertSame([], $result['skippedOnly']);
        $this->assertSame(0, $result['skippedOnlyCount']);
    }

    #[Test]
    public function record_with_schema_validated_false_marks_skipped_only(): void
    {
        OpenApiCoverageTracker::record('petstore-3.0', 'GET', '/v1/pets', schemaValidated: false);

        $result = OpenApiCoverageTracker::computeCoverage('petstore-3.0');

        $this->assertContains('GET /v1/pets', $result['covered']);
        $this->assertSame(['GET /v1/pets'], $result['skippedOnly']);
        $this->assertSame(1, $result['skippedOnlyCount']);
    }

    #[Test]
    public function validated_record_overrides_prior_skipped_record(): void
    {
        OpenApiCoverageTracker::record('petstore-3.0', 'GET', '/v1/pets', schemaValidated: false);
        OpenApiCoverageTracker::record('petstore-3.0', 'GET', '/v1/pets', schemaValidated: true);

        $result = OpenApiCoverageTracker::computeCoverage('petstore-3.0');

        $this->assertContains('GET /v1/pets', $result['covered']);
        $this->assertSame([], $result['skippedOnly']);
        $this->assertSame(0, $result['skippedOnlyCount']);
    }

    #[Test]
    public function skipped_record_does_not_demote_validated_endpoint(): void
    {
        OpenApiCoverageTracker::record('petstore-3.0', 'GET', '/v1/pets', schemaValidated: true);
        OpenApiCoverageTracker::record('petstore-3.0', 'GET', '/v1/pets', schemaValidated: false);

        $result = OpenApiCoverageTracker::computeCoverage('petstore-3.0');

        $this->assertContains('GET /v1/pets', $result['covered']);
        $this->assertSame([], $result['skippedOnly']);
        $this->assertSame(0, $result['skippedOnlyCount']);
    }

    #[Test]
    public function compute_coverage_returns_skipped_only_sorted(): void
    {
        OpenApiCoverageTracker::record('petstore-3.0', 'POST', '/v1/pets', schemaValidated: false);
        OpenApiCoverageTracker::record('petstore-3.0', 'GET', '/v1/pets', schemaValidated: false);
        OpenApiCoverageTracker::record('petstore-3.0', 'GET', '/v1/pets/{petId}', schemaValidated: true);

        $result = OpenApiCoverageTracker::computeCoverage('petstore-3.0');

        $this->assertSame(['GET /v1/pets', 'POST /v1/pets'], $result['skippedOnly']);
        $this->assertSame(2, $result['skippedOnlyCount']);
        $this->assertSame(3, $result['coveredCount']);
    }

    #[Test]
    public function get_covered_preserves_external_shape(): void
    {
        OpenApiCoverageTracker::record('petstore-3.0', 'GET', '/v1/pets', schemaValidated: false);

        $covered = OpenApiCoverageTracker::getCovered();

        $this->assertSame(['petstore-3.0' => ['GET /v1/pets' => true]], $covered);
    }
}
