<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Coverage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;

/**
 * Issue #229: structural guarantee that two independently-constructed
 * {@see OpenApiCoverageTracker} instances do not share state. This is the
 * invariant the refactor away from a static singleton buys us; a regression
 * here would put us back in the world where test isolation depended on
 * everyone remembering to call `::reset()` in `setUp`/`tearDown`.
 *
 * Spec loader still uses static configuration — only the tracker is
 * instance-based here, mirroring the scope of the refactor.
 */
final class OpenApiCoverageTrackerInstanceIsolationTest extends TestCase
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
    public function two_instances_do_not_share_recorded_requests(): void
    {
        $a = new OpenApiCoverageTracker();
        $b = new OpenApiCoverageTracker();

        $a->recordRequestOn('petstore-3.0', 'GET', '/v1/pets');

        $this->assertTrue($a->hasAnyCoverageOn('petstore-3.0'));
        $this->assertFalse($b->hasAnyCoverageOn('petstore-3.0'));
    }

    #[Test]
    public function two_instances_do_not_share_recorded_responses(): void
    {
        $a = new OpenApiCoverageTracker();
        $b = new OpenApiCoverageTracker();

        $a->recordResponseOn(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );

        $this->assertNotSame([], $a->getCoveredOn());
        $this->assertSame([], $b->getCoveredOn());
    }

    #[Test]
    public function export_state_round_trips_through_a_second_instance(): void
    {
        $source = new OpenApiCoverageTracker();
        $source->recordResponseOn(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );

        $sink = new OpenApiCoverageTracker();
        $sink->importStateOn($source->exportStateOn());

        $this->assertSame($source->getCoveredOn(), $sink->getCoveredOn());
    }

    #[Test]
    public function reset_on_instance_does_not_affect_sibling_instance(): void
    {
        $a = new OpenApiCoverageTracker();
        $b = new OpenApiCoverageTracker();

        $a->recordRequestOn('petstore-3.0', 'GET', '/v1/pets');
        $b->recordRequestOn('petstore-3.0', 'POST', '/v1/pets');

        $a->resetOn();

        $this->assertFalse($a->hasAnyCoverageOn('petstore-3.0'));
        $this->assertTrue($b->hasAnyCoverageOn('petstore-3.0'));
    }

    #[Test]
    public function static_facade_routes_to_the_current_instance(): void
    {
        $injected = new OpenApiCoverageTracker();
        $previous = OpenApiCoverageTracker::current();
        OpenApiCoverageTracker::setCurrent($injected);

        try {
            OpenApiCoverageTracker::recordRequest('petstore-3.0', 'GET', '/v1/pets');

            $this->assertTrue($injected->hasAnyCoverageOn('petstore-3.0'));
        } finally {
            // Restore so we don't leak the injected instance into other tests
            // that share the process-global facade.
            OpenApiCoverageTracker::setCurrent($previous);
        }
    }
}
