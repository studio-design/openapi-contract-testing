<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Strict;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredTracker;

/**
 * Issue #229: structural guarantee that two independently-constructed
 * {@see StrictRequiredTracker} instances do not share state. Mirrors the
 * matching coverage-tracker isolation test — if either tracker regresses
 * back to global mutable state, the suite catches it without depending on
 * a test author remembering to call `::reset()` everywhere.
 */
final class StrictRequiredTrackerInstanceIsolationTest extends TestCase
{
    #[Test]
    public function two_instances_do_not_share_observations(): void
    {
        $a = new StrictRequiredTracker();
        $b = new StrictRequiredTracker();

        $a->recordOn('front', 'GET', '/x', '200', 'application/json', [
            '/' => ['a', 'b'],
        ]);

        $this->assertSame(['front'], $a->recordedSpecsOn());
        $this->assertSame([], $b->recordedSpecsOn());
    }

    #[Test]
    public function reset_on_instance_does_not_affect_sibling_instance(): void
    {
        $a = new StrictRequiredTracker();
        $b = new StrictRequiredTracker();

        $a->recordOn('front', 'GET', '/x', '200', 'application/json', [
            '/' => ['a'],
        ]);
        $b->recordOn('back', 'GET', '/y', '200', 'application/json', [
            '/' => ['b'],
        ]);

        $a->resetOn();

        $this->assertSame([], $a->recordedSpecsOn());
        $this->assertSame(['back'], $b->recordedSpecsOn());
    }

    #[Test]
    public function export_state_round_trips_through_a_second_instance(): void
    {
        $source = new StrictRequiredTracker();
        $source->recordOn('front', 'GET', '/x', '200', 'application/json', [
            '/' => ['a', 'b'],
        ]);

        $sink = new StrictRequiredTracker();
        $sink->importStateOn($source->exportStateOn());

        $this->assertSame(
            $source->getObservationsOn('front'),
            $sink->getObservationsOn('front'),
        );
    }

    #[Test]
    public function static_facade_routes_to_the_current_instance(): void
    {
        $injected = new StrictRequiredTracker();
        $previous = StrictRequiredTracker::current();
        StrictRequiredTracker::setCurrent($injected);

        try {
            StrictRequiredTracker::record('front', 'GET', '/x', '200', 'application/json', [
                '/' => ['a'],
            ]);

            $this->assertSame(['front'], $injected->recordedSpecsOn());
        } finally {
            // Restore so we don't leak the injected instance into other tests
            // that share the process-global facade.
            StrictRequiredTracker::setCurrent($previous);
        }
    }
}
