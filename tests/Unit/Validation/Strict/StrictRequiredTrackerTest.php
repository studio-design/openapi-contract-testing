<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation\Strict;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

final class StrictRequiredTrackerTest extends TestCase
{
    private StrictRequiredTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();
        // Issue #229: per-test tracker instance — no global reset needed.
        $this->tracker = new StrictRequiredTracker();
    }

    #[Test]
    public function single_observation_keeps_full_pointer_map(): void
    {
        $this->tracker->recordOn('front', 'GET', '/x', '200', 'application/json', [
            '/' => ['a', 'b', 'c'],
        ]);

        $observations = $this->tracker->getObservationsOn('front');
        $this->assertSame(
            [
                'GET /x' => [
                    '200:application/json' => ['hits' => 1, 'pointers' => ['/' => ['a', 'b', 'c']]],
                ],
            ],
            $observations,
        );
    }

    #[Test]
    public function multiple_observations_intersect_keys_at_each_pointer(): void
    {
        $this->tracker->recordOn('front', 'GET', '/x', '200', 'application/json', [
            '/' => ['a', 'b', 'c'],
        ]);
        $this->tracker->recordOn('front', 'GET', '/x', '200', 'application/json', [
            '/' => ['a', 'b'],
        ]);
        $this->tracker->recordOn('front', 'GET', '/x', '200', 'application/json', [
            '/' => ['a', 'b', 'd'],
        ]);

        $this->assertSame(
            [
                'GET /x' => [
                    '200:application/json' => ['hits' => 3, 'pointers' => ['/' => ['a', 'b']]],
                ],
            ],
            $this->tracker->getObservationsOn('front'),
        );
    }

    #[Test]
    public function empty_body_observation_keeps_pointer_with_empty_key_list(): void
    {
        $this->tracker->recordOn('front', 'GET', '/x', '200', 'application/json', [
            '/' => ['a', 'b'],
        ]);
        $this->tracker->recordOn('front', 'GET', '/x', '200', 'application/json', [
            '/' => [],
        ]);

        $this->assertSame(
            [
                'GET /x' => [
                    '200:application/json' => ['hits' => 2, 'pointers' => ['/' => []]],
                ],
            ],
            $this->tracker->getObservationsOn('front'),
        );
    }

    #[Test]
    public function nested_pointers_are_tracked_independently_per_pointer(): void
    {
        $this->tracker->recordOn('front', 'GET', '/x', '200', 'application/json', [
            '/' => ['data'],
            '/data' => ['id', 'name', 'created_at'],
            '/data/tags[*]' => ['t'],
        ]);
        $this->tracker->recordOn('front', 'GET', '/x', '200', 'application/json', [
            '/' => ['data'],
            '/data' => ['id', 'name', 'created_at', 'extra'],
            '/data/tags[*]' => ['t'],
        ]);

        $this->assertSame(
            [
                'GET /x' => [
                    '200:application/json' => [
                        'hits' => 2,
                        'pointers' => [
                            '/' => ['data'],
                            '/data' => ['created_at', 'id', 'name'],
                            '/data/tags[*]' => ['t'],
                        ],
                    ],
                ],
            ],
            $this->tracker->getObservationsOn('front'),
        );
    }

    #[Test]
    public function pointer_absent_in_subsequent_observation_is_dropped(): void
    {
        // obs#1 has /items[*]; obs#2 has empty items array (no [*] pointer
        // emitted by the walker). The cross-observation rule must drop the
        // pointer entirely — "always observed" requires the pointer itself.
        $this->tracker->recordOn('front', 'GET', '/x', '200', 'application/json', [
            '/' => ['items'],
            '/items[*]' => ['id', 'name'],
        ]);
        $this->tracker->recordOn('front', 'GET', '/x', '200', 'application/json', [
            '/' => ['items'],
        ]);

        $this->assertSame(
            [
                'GET /x' => [
                    '200:application/json' => [
                        'hits' => 2,
                        'pointers' => ['/' => ['items']],
                    ],
                ],
            ],
            $this->tracker->getObservationsOn('front'),
        );
    }

    #[Test]
    public function pointer_only_in_second_observation_is_dropped(): void
    {
        $this->tracker->recordOn('front', 'GET', '/x', '200', 'application/json', [
            '/' => ['items'],
        ]);
        $this->tracker->recordOn('front', 'GET', '/x', '200', 'application/json', [
            '/' => ['items'],
            '/items[*]' => ['id'],
        ]);

        $this->assertSame(
            [
                'GET /x' => [
                    '200:application/json' => [
                        'hits' => 2,
                        'pointers' => ['/' => ['items']],
                    ],
                ],
            ],
            $this->tracker->getObservationsOn('front'),
        );
    }

    #[Test]
    public function different_status_keys_are_independent(): void
    {
        $this->tracker->recordOn('front', 'GET', '/x', '200', 'application/json', ['/' => ['a']]);
        $this->tracker->recordOn('front', 'GET', '/x', '404', 'application/json', ['/' => ['error']]);

        $this->assertSame(
            [
                'GET /x' => [
                    '200:application/json' => ['hits' => 1, 'pointers' => ['/' => ['a']]],
                    '404:application/json' => ['hits' => 1, 'pointers' => ['/' => ['error']]],
                ],
            ],
            $this->tracker->getObservationsOn('front'),
        );
    }

    #[Test]
    public function different_content_types_are_independent(): void
    {
        $this->tracker->recordOn('front', 'GET', '/x', '200', 'application/json', ['/' => ['a']]);
        $this->tracker->recordOn('front', 'GET', '/x', '200', 'application/problem+json', ['/' => ['detail']]);

        $this->assertSame(
            [
                'GET /x' => [
                    '200:application/json' => ['hits' => 1, 'pointers' => ['/' => ['a']]],
                    '200:application/problem+json' => ['hits' => 1, 'pointers' => ['/' => ['detail']]],
                ],
            ],
            $this->tracker->getObservationsOn('front'),
        );
    }

    #[Test]
    public function different_specs_are_independent(): void
    {
        $this->tracker->recordOn('front', 'GET', '/x', '200', 'application/json', ['/' => ['a']]);
        $this->tracker->recordOn('admin', 'GET', '/x', '200', 'application/json', ['/' => ['b']]);

        $this->assertSame(
            ['GET /x' => ['200:application/json' => ['hits' => 1, 'pointers' => ['/' => ['a']]]]],
            $this->tracker->getObservationsOn('front'),
        );
        $this->assertSame(
            ['GET /x' => ['200:application/json' => ['hits' => 1, 'pointers' => ['/' => ['b']]]]],
            $this->tracker->getObservationsOn('admin'),
        );
    }

    #[Test]
    public function record_normalises_method_to_uppercase(): void
    {
        $this->tracker->recordOn('front', 'get', '/x', '200', 'application/json', ['/' => ['a']]);

        $this->assertArrayHasKey('GET /x', $this->tracker->getObservationsOn('front'));
    }

    #[Test]
    public function record_normalises_pointer_lists_to_sorted_unique(): void
    {
        $this->tracker->recordOn('front', 'GET', '/x', '200', 'application/json', [
            '/' => ['z', 'a', 'a', 'm'],
        ]);

        $this->assertSame(
            ['GET /x' => ['200:application/json' => ['hits' => 1, 'pointers' => ['/' => ['a', 'm', 'z']]]]],
            $this->tracker->getObservationsOn('front'),
        );
    }

    #[Test]
    public function reset_clears_all_specs(): void
    {
        $this->tracker->recordOn('front', 'GET', '/x', '200', 'application/json', ['/' => ['a']]);
        $this->tracker->recordOn('admin', 'GET', '/y', '200', 'application/json', ['/' => ['b']]);

        $this->tracker->resetOn();

        $this->assertSame([], $this->tracker->getObservationsOn('front'));
        $this->assertSame([], $this->tracker->getObservationsOn('admin'));
    }

    #[Test]
    public function export_and_import_round_trip_with_nested_pointers(): void
    {
        $this->tracker->recordOn('front', 'GET', '/x', '200', 'application/json', [
            '/' => ['data'],
            '/data' => ['id', 'name'],
        ]);
        $this->tracker->recordOn('front', 'GET', '/x', '200', 'application/json', [
            '/' => ['data'],
            '/data' => ['id', 'name', 'extra'],
        ]);
        $this->tracker->recordOn('front', 'POST', '/y', '201', 'application/json', [
            '/' => ['id'],
        ]);

        $exported = $this->tracker->exportStateOn();
        $this->assertSame(2, $exported['version']);

        $sink = new StrictRequiredTracker();
        $this->assertSame([], $sink->getObservationsOn('front'));

        $sink->importStateOn($exported);

        $this->assertSame(
            [
                'GET /x' => [
                    '200:application/json' => [
                        'hits' => 2,
                        'pointers' => [
                            '/' => ['data'],
                            '/data' => ['id', 'name'],
                        ],
                    ],
                ],
                'POST /y' => [
                    '201:application/json' => ['hits' => 1, 'pointers' => ['/' => ['id']]],
                ],
            ],
            $sink->getObservationsOn('front'),
        );
    }

    #[Test]
    public function import_unions_with_existing_via_intersection(): void
    {
        $this->tracker->recordOn('front', 'GET', '/x', '200', 'application/json', [
            '/' => ['a', 'b', 'c'],
            '/nested' => ['x', 'y'],
        ]);

        $this->tracker->importStateOn([
            'version' => 2,
            'observations' => [
                'front' => [
                    'GET /x' => [
                        '200:application/json' => [
                            'hits' => 2,
                            'pointers' => [
                                '/' => ['a', 'b'],
                                '/nested' => ['x'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame(
            [
                'GET /x' => [
                    '200:application/json' => [
                        'hits' => 3,
                        'pointers' => [
                            '/' => ['a', 'b'],
                            '/nested' => ['x'],
                        ],
                    ],
                ],
            ],
            $this->tracker->getObservationsOn('front'),
        );
    }

    #[Test]
    public function import_drops_pointer_absent_on_one_side(): void
    {
        $this->tracker->recordOn('front', 'GET', '/x', '200', 'application/json', [
            '/' => ['a'],
            '/items[*]' => ['id', 'name'],
        ]);

        $this->tracker->importStateOn([
            'version' => 2,
            'observations' => [
                'front' => [
                    'GET /x' => [
                        '200:application/json' => [
                            'hits' => 1,
                            'pointers' => ['/' => ['a']],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame(
            [
                'GET /x' => [
                    '200:application/json' => [
                        'hits' => 2,
                        'pointers' => ['/' => ['a']],
                    ],
                ],
            ],
            $this->tracker->getObservationsOn('front'),
        );
    }

    #[Test]
    public function import_rejects_unknown_version(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported strict_required state version');

        $this->tracker->importStateOn([
            'version' => 99,
            'observations' => [],
        ]);
    }

    #[Test]
    public function import_rejects_v1_always_present_shape_loudly(): void
    {
        // v1 payloads carry `version: 1` so the version check fires first.
        // Test pins that error message — paratest worker upgrades must be
        // a single atomic operation per docs/strict-required.md.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported strict_required state version: got 1, expected 2');

        $this->tracker->importStateOn([
            'version' => 1,
            'observations' => [
                'front' => ['GET /x' => ['200:application/json' => ['hits' => 1, 'alwaysPresent' => ['a']]]],
            ],
        ]);
    }

    #[Test]
    public function record_rejects_non_string_key_in_pointer_list(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('list<string>');

        $this->tracker->recordOn('front', 'GET', '/x', '200', 'application/json', [
            '/' => ['a', 42, 'c'],
        ]);
    }

    #[Test]
    public function record_rejects_empty_pointer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty string pointers');

        $this->tracker->recordOn('front', 'GET', '/x', '200', 'application/json', [
            '' => ['a'],
        ]);
    }

    #[Test]
    public function import_rejects_missing_observations_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"observations" object');

        $this->tracker->importStateOn(['version' => 2]);
    }

    #[Test]
    public function import_rejects_zero_hits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('integer hits >= 1');

        $this->tracker->importStateOn([
            'version' => 2,
            'observations' => [
                'front' => ['GET /x' => ['200:application/json' => ['hits' => 0, 'pointers' => []]]],
            ],
        ]);
    }

    #[Test]
    public function import_rejects_non_string_entry_in_pointer_keys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('entries must be strings');

        $this->tracker->importStateOn([
            'version' => 2,
            'observations' => [
                'front' => ['GET /x' => ['200:application/json' => [
                    'hits' => 1,
                    'pointers' => ['/' => ['ok', 42]],
                ]]],
            ],
        ]);
    }

    #[Test]
    public function import_rejects_v2_row_carrying_always_present_field(): void
    {
        // Payload claims v2 but uses v1 row shape — the validator surfaces
        // the v1-alwaysPresent hint so users get a clear upgrade path.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('v1 "alwaysPresent" field');

        $this->tracker->importStateOn([
            'version' => 2,
            'observations' => [
                'front' => ['GET /x' => ['200:application/json' => [
                    'hits' => 1,
                    'alwaysPresent' => ['a'],
                ]]],
            ],
        ]);
    }

    #[Test]
    public function import_does_not_partially_mutate_on_late_payload_failure(): void
    {
        // Two-pass validation invariant: a malformed entry deep in the
        // payload must not leave the tracker in a partially-merged state.
        $this->tracker->recordOn('front', 'GET', '/x', '200', 'application/json', [
            '/' => ['a', 'b'],
        ]);

        $payload = [
            'version' => 2,
            'observations' => [
                'front' => ['GET /x' => ['200:application/json' => [
                    'hits' => 1,
                    'pointers' => ['/' => ['a']],
                ]]],
                'admin' => ['GET /y' => ['200:application/json' => [
                    'hits' => 0,
                    'pointers' => [],
                ]]],
            ],
        ];

        try {
            $this->tracker->importStateOn($payload);
            $this->fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(
            [
                'GET /x' => [
                    '200:application/json' => ['hits' => 1, 'pointers' => ['/' => ['a', 'b']]],
                ],
            ],
            $this->tracker->getObservationsOn('front'),
        );
        $this->assertSame([], $this->tracker->getObservationsOn('admin'));
    }
}
