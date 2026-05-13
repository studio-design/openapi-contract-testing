<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Coverage;

use const JSON_THROW_ON_ERROR;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;

use function json_decode;
use function json_encode;

/**
 * Pins the JSON-safe state shape produced by {@see OpenApiCoverageTracker::exportStateOn()}
 * and the union-merge semantics of {@see OpenApiCoverageTracker::importStateOn()}.
 *
 * The merge CLI loads N worker sidecars by calling importStateOn() N times, so
 * the merge rules MUST mirror the live recording rules: validated wins over
 * skipped, hits accumulate, and the latest non-null skipReason wins.
 */
class OpenApiCoverageTrackerStateSerializationTest extends TestCase
{
    private OpenApiCoverageTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();
        // Issue #229: per-test tracker instance — no process-global reset
        // dance needed at the class boundary.
        $this->tracker = new OpenApiCoverageTracker();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function export_state_returns_empty_payload_when_nothing_recorded(): void
    {
        $state = $this->tracker->exportStateOn();

        $this->assertSame(['version' => 1, 'specs' => []], $state);
    }

    #[Test]
    public function export_state_serializes_request_only_endpoint(): void
    {
        $this->tracker->recordRequestOn('petstore-3.0', 'GET', '/v1/pets');

        $state = $this->tracker->exportStateOn();

        $this->assertSame([
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => true,
                        'requestSkipReason' => null,
                        'responses' => [],
                    ],
                ],
            ],
        ], $state);
    }

    #[Test]
    public function export_state_serializes_validated_response(): void
    {
        $this->tracker->recordResponseOn(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );

        $state = $this->tracker->exportStateOn();

        $this->assertSame([
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => false,
                        'requestSkipReason' => null,
                        'responses' => [
                            '200:application/json' => [
                                'state' => 'validated',
                                'hits' => 1,
                                'skipReason' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ], $state);
    }

    #[Test]
    public function export_state_serializes_skipped_response_with_reason(): void
    {
        $this->tracker->recordResponseOn(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '503',
            null,
            schemaValidated: false,
            skipReason: 'status 503 matched skip pattern 5\\d\\d',
        );

        $state = $this->tracker->exportStateOn();

        $this->assertSame([
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => false,
                        'requestSkipReason' => null,
                        'responses' => [
                            '503:*' => [
                                'state' => 'skipped',
                                'hits' => 1,
                                'skipReason' => 'status 503 matched skip pattern 5\\d\\d',
                            ],
                        ],
                    ],
                ],
            ],
        ], $state);
    }

    #[Test]
    public function export_state_round_trips_through_json(): void
    {
        $this->tracker->recordRequestOn('petstore-3.0', 'GET', '/v1/pets');
        $this->tracker->recordResponseOn(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        $this->tracker->recordResponseOn(
            'petstore-3.0',
            'GET',
            '/v1/pets/{petId}',
            '503',
            null,
            schemaValidated: false,
            skipReason: 'matched 5\\d\\d',
        );

        $original = $this->tracker->exportStateOn();
        $json = json_encode($original, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $sink = new OpenApiCoverageTracker();
        $sink->importStateOn($decoded);

        $this->assertSame($original, $sink->exportStateOn());
    }

    #[Test]
    public function import_state_is_additive_for_disjoint_specs(): void
    {
        // Worker A's state.
        $workerATracker = new OpenApiCoverageTracker();
        $workerATracker->recordResponseOn(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        $workerA = $workerATracker->exportStateOn();

        // Worker B's state, captured from a fresh tracker.
        $workerBTracker = new OpenApiCoverageTracker();
        $workerBTracker->recordResponseOn(
            'range-keys',
            'GET',
            '/widgets-default',
            '200',
            'application/json',
            schemaValidated: true,
        );
        $workerB = $workerBTracker->exportStateOn();

        // Merge both into one tracker.
        $this->tracker->importStateOn($workerA);
        $this->tracker->importStateOn($workerB);

        $merged = $this->tracker->exportStateOn();
        $this->assertArrayHasKey('petstore-3.0', $merged['specs']);
        $this->assertArrayHasKey('range-keys', $merged['specs']);
        $this->assertSame(
            1,
            $merged['specs']['petstore-3.0']['GET /v1/pets']['responses']['200:application/json']['hits'],
        );
        $this->assertSame(
            1,
            $merged['specs']['range-keys']['GET /widgets-default']['responses']['200:application/json']['hits'],
        );
    }

    #[Test]
    public function import_state_accumulates_hits_for_same_pair(): void
    {
        // Two workers both validated GET /v1/pets 200:application/json once.
        $worker = [
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => false,
                        'responses' => [
                            '200:application/json' => [
                                'state' => 'validated',
                                'hits' => 1,
                                'skipReason' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->tracker->importStateOn($worker);
        $this->tracker->importStateOn($worker);

        $merged = $this->tracker->exportStateOn();
        $this->assertSame(
            2,
            $merged['specs']['petstore-3.0']['GET /v1/pets']['responses']['200:application/json']['hits'],
        );
    }

    #[Test]
    public function import_state_promotes_skipped_to_validated(): void
    {
        $skippedFirst = [
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => false,
                        'responses' => [
                            '200:application/json' => [
                                'state' => 'skipped',
                                'hits' => 2,
                                'skipReason' => 'matched 2xx-skip',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $validatedSecond = [
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => false,
                        'responses' => [
                            '200:application/json' => [
                                'state' => 'validated',
                                'hits' => 1,
                                'skipReason' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->tracker->importStateOn($skippedFirst);
        $this->tracker->importStateOn($validatedSecond);

        $merged = $this->tracker->exportStateOn();
        $row = $merged['specs']['petstore-3.0']['GET /v1/pets']['responses']['200:application/json'];
        $this->assertSame('validated', $row['state']);
        $this->assertNull($row['skipReason']);
        // Bulk merge sums hits across both sources — matches sequential
        // recordResponseOn() calls where hits++ runs unconditionally and
        // state promotion does not roll the counter back.
        $this->assertSame(3, $row['hits']);
    }

    #[Test]
    public function import_state_keeps_validated_when_skipped_arrives_later(): void
    {
        $validatedFirst = [
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => false,
                        'responses' => [
                            '200:application/json' => [
                                'state' => 'validated',
                                'hits' => 3,
                                'skipReason' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $skippedSecond = [
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => false,
                        'responses' => [
                            '200:application/json' => [
                                'state' => 'skipped',
                                'hits' => 1,
                                'skipReason' => 'late skip',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->tracker->importStateOn($validatedFirst);
        $this->tracker->importStateOn($skippedSecond);

        $merged = $this->tracker->exportStateOn();
        $row = $merged['specs']['petstore-3.0']['GET /v1/pets']['responses']['200:application/json'];
        $this->assertSame('validated', $row['state']);
        $this->assertNull($row['skipReason']);
        $this->assertSame(4, $row['hits']);
    }

    #[Test]
    public function import_state_keeps_latest_skip_reason_when_both_sides_skipped(): void
    {
        $first = [
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => false,
                        'responses' => [
                            '503:*' => [
                                'state' => 'skipped',
                                'hits' => 2,
                                'skipReason' => 'old reason',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $second = [
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => false,
                        'responses' => [
                            '503:*' => [
                                'state' => 'skipped',
                                'hits' => 1,
                                'skipReason' => 'new reason',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->tracker->importStateOn($first);
        $this->tracker->importStateOn($second);

        $merged = $this->tracker->exportStateOn();
        $row = $merged['specs']['petstore-3.0']['GET /v1/pets']['responses']['503:*'];
        $this->assertSame('skipped', $row['state']);
        $this->assertSame('new reason', $row['skipReason']);
        $this->assertSame(3, $row['hits']);
    }

    #[Test]
    public function import_state_unions_request_reached_flag(): void
    {
        $reached = [
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => true,
                        'responses' => [],
                    ],
                ],
            ],
        ];
        $notReached = [
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => false,
                        'responses' => [
                            '200:application/json' => [
                                'state' => 'validated',
                                'hits' => 1,
                                'skipReason' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->tracker->importStateOn($reached);
        $this->tracker->importStateOn($notReached);

        $merged = $this->tracker->exportStateOn();
        $endpoint = $merged['specs']['petstore-3.0']['GET /v1/pets'];
        $this->assertTrue($endpoint['requestReached']);
        $this->assertArrayHasKey('200:application/json', $endpoint['responses']);
    }

    #[Test]
    public function import_state_rejects_unknown_version(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported coverage state version');

        $this->tracker->importStateOn(['version' => 99, 'specs' => []]);
    }

    #[Test]
    public function import_state_rejects_missing_version(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing "version"');

        $this->tracker->importStateOn(['specs' => []]);
    }

    #[Test]
    public function import_state_rejects_invalid_response_state(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid response state');

        $this->tracker->importStateOn([
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => false,
                        'responses' => [
                            '200:application/json' => [
                                'state' => 'bogus',
                                'hits' => 1,
                                'skipReason' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    #[Test]
    public function import_state_rejects_non_string_spec_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid spec entry');

        $this->tracker->importStateOn([
            'version' => 1,
            'specs' => [
                42 => ['GET /v1/pets' => ['requestReached' => true, 'responses' => []]],
            ],
        ]);
    }

    #[Test]
    public function import_state_rejects_non_array_endpoint_entry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid endpoint entry');

        $this->tracker->importStateOn([
            'version' => 1,
            'specs' => [
                'petstore-3.0' => ['GET /v1/pets' => 'not an array'],
            ],
        ]);
    }

    #[Test]
    public function import_state_rejects_non_array_response_row(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid response entry');

        $this->tracker->importStateOn([
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => false,
                        'responses' => ['200:application/json' => 'not an array'],
                    ],
                ],
            ],
        ]);
    }

    #[Test]
    public function import_state_rejects_non_string_response_state(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid response state');

        $this->tracker->importStateOn([
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'GET /v1/pets' => [
                        'requestReached' => false,
                        'responses' => ['200:application/json' => [
                            'state' => 42,
                            'hits' => 1,
                            'skipReason' => null,
                        ]],
                    ],
                ],
            ],
        ]);
    }

    #[Test]
    public function import_state_does_not_partially_apply_when_payload_is_invalid(): void
    {
        // Pre-PR, importer mutated state per-endpoint and threw partway,
        // leaving the tracker inconsistent. Two-pass validate-then-apply
        // must roll back cleanly: nothing is mutated when validation
        // would eventually fail.
        $this->tracker->recordResponseOn(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        $beforeSnapshot = $this->tracker->exportStateOn();

        try {
            $this->tracker->importStateOn([
                'version' => 1,
                'specs' => [
                    'petstore-3.0' => [
                        'POST /v1/pets' => [
                            'requestReached' => false,
                            'responses' => ['201:application/json' => [
                                'state' => 'validated',
                                'hits' => 1,
                                'skipReason' => null,
                            ]],
                        ],
                        'GET /v1/widgets' => [
                            'requestReached' => false,
                            'responses' => ['200:application/json' => [
                                'state' => 'bogus',
                                'hits' => 1,
                                'skipReason' => null,
                            ]],
                        ],
                    ],
                ],
            ]);
            $this->fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            // Expected — the second endpoint's "bogus" state must abort.
        }

        $this->assertSame($beforeSnapshot, $this->tracker->exportStateOn());
    }

    #[Test]
    public function export_state_round_trip_preserves_request_skip_reason(): void
    {
        // Issue #179: a recordRequestOn call with a skip reason (downgraded
        // failure on documented 4xx) must serialize round-trip cleanly so
        // paratest worker sidecars carry the field across to the merge CLI.
        $this->tracker->recordRequestOn(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            'request validation skipped: response 422 is documented (spec key 422)',
        );

        $original = $this->tracker->exportStateOn();
        $json = json_encode($original, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $sink = new OpenApiCoverageTracker();
        $sink->importStateOn($decoded);

        $this->assertSame($original, $sink->exportStateOn());
        $endpoint = $original['specs']['petstore-3.0']['POST /v1/pets'];
        $this->assertTrue($endpoint['requestReached']);
        $this->assertSame(
            'request validation skipped: response 422 is documented (spec key 422)',
            $endpoint['requestSkipReason'],
        );
    }

    #[Test]
    public function import_state_tolerates_payloads_without_request_skip_reason_field(): void
    {
        // Strictly additive: paratest sidecars written by an older library
        // version (no `requestSkipReason` key) must still import cleanly. The
        // missing field defaults to null. This keeps the wire format
        // backward-compatible without a STATE_FORMAT_VERSION bump.
        $this->tracker->importStateOn([
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'POST /v1/pets' => [
                        'requestReached' => true,
                        'responses' => [],
                        // no 'requestSkipReason' key
                    ],
                ],
            ],
        ]);

        $state = $this->tracker->exportStateOn();
        $endpoint = $state['specs']['petstore-3.0']['POST /v1/pets'];
        $this->assertTrue($endpoint['requestReached']);
        $this->assertNull($endpoint['requestSkipReason']);
    }

    #[Test]
    public function import_state_rejects_non_string_request_skip_reason(): void
    {
        // C2 hardening: a corrupt sidecar with a non-null, non-string
        // requestSkipReason (int, array, bool — i.e. real corruption from
        // a buggy serializer or hand-edit) must throw rather than silently
        // coercing to null. Mirrors the response-side `state` strictness.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid requestSkipReason in coverage state payload');

        $this->tracker->importStateOn([
            'version' => 1,
            'specs' => [
                'petstore-3.0' => [
                    'POST /v1/pets' => [
                        'requestReached' => true,
                        'requestSkipReason' => 42,
                        'responses' => [],
                    ],
                ],
            ],
        ]);
    }

    #[Test]
    public function reconcile_request_agrees_between_record_and_import_paths(): void
    {
        // Companion to reconcile_response_agrees_between_record_and_import_paths:
        // the bulk-merge importStateOn branch must produce the same final
        // requestSkipReason as the sequential recordRequestOn branch when fed
        // equivalent observations. Pinned here so a future tweak to one
        // path and not the other is caught immediately.
        $sequentialTracker = new OpenApiCoverageTracker();
        $sequentialTracker->recordRequestOn('petstore-3.0', 'GET', '/v1/pets', 'reason-1');
        $sequentialTracker->recordRequestOn('petstore-3.0', 'GET', '/v1/pets', 'reason-2');
        $sequentialTracker->recordRequestOn('petstore-3.0', 'GET', '/v1/pets');
        $sequential = $sequentialTracker->exportStateOn();

        $bulkTracker = new OpenApiCoverageTracker();
        $bulkTracker->importStateOn([
            'version' => 1,
            'specs' => ['petstore-3.0' => ['GET /v1/pets' => [
                'requestReached' => true,
                'requestSkipReason' => 'reason-2',
                'responses' => [],
            ]]],
        ]);
        $bulkTracker->importStateOn([
            'version' => 1,
            'specs' => ['petstore-3.0' => ['GET /v1/pets' => [
                'requestReached' => true,
                'requestSkipReason' => null,
                'responses' => [],
            ]]],
        ]);
        $bulk = $bulkTracker->exportStateOn();

        $this->assertSame($sequential, $bulk);
    }

    #[Test]
    public function import_state_does_not_demote_validated_request_to_skipped(): void
    {
        // Mirror of import_state_keeps_validated_when_skipped_arrives_later
        // for the request side. Once a worker recorded a clean request
        // validation, a later worker's downgrade for the same endpoint
        // must not flip it back to skipped.
        $this->tracker->importStateOn([
            'version' => 1,
            'specs' => ['petstore-3.0' => ['GET /v1/pets' => [
                'requestReached' => true,
                'requestSkipReason' => null,
                'responses' => [],
            ]]],
        ]);
        $this->tracker->importStateOn([
            'version' => 1,
            'specs' => ['petstore-3.0' => ['GET /v1/pets' => [
                'requestReached' => true,
                'requestSkipReason' => 'late downgrade',
                'responses' => [],
            ]]],
        ]);

        $merged = $this->tracker->exportStateOn();
        $this->assertNull($merged['specs']['petstore-3.0']['GET /v1/pets']['requestSkipReason']);
    }

    #[Test]
    public function import_state_after_record_response_preserves_request_skip_reason(): void
    {
        // C1 regression in the bulk-merge path: a worker A that wrote a
        // response-only sidecar must not erase the skipReason carried by
        // worker B's request-side payload when they merge.
        $this->tracker->importStateOn([
            'version' => 1,
            'specs' => ['petstore-3.0' => ['GET /v1/pets' => [
                'requestReached' => false,
                'requestSkipReason' => null,
                'responses' => [
                    '200:application/json' => ['state' => 'validated', 'hits' => 1, 'skipReason' => null],
                ],
            ]]],
        ]);
        $this->tracker->importStateOn([
            'version' => 1,
            'specs' => ['petstore-3.0' => ['GET /v1/pets' => [
                'requestReached' => true,
                'requestSkipReason' => 'downgraded after response',
                'responses' => [],
            ]]],
        ]);

        $merged = $this->tracker->exportStateOn();
        $endpoint = $merged['specs']['petstore-3.0']['GET /v1/pets'];
        $this->assertTrue($endpoint['requestReached']);
        $this->assertSame('downgraded after response', $endpoint['requestSkipReason']);
    }

    #[Test]
    public function reconcile_response_agrees_between_record_and_import_paths(): void
    {
        // Sequential single-record path: 2 skip + 1 validated.
        $sequentialTracker = new OpenApiCoverageTracker();
        $sequentialTracker->recordResponseOn('petstore-3.0', 'GET', '/v1/pets', '200', 'application/json', schemaValidated: false, skipReason: 'reason-1');
        $sequentialTracker->recordResponseOn('petstore-3.0', 'GET', '/v1/pets', '200', 'application/json', schemaValidated: false, skipReason: 'reason-2');
        $sequentialTracker->recordResponseOn('petstore-3.0', 'GET', '/v1/pets', '200', 'application/json', schemaValidated: true);
        $sequential = $sequentialTracker->exportStateOn();

        // Bulk-merge path: equivalent observations split across two payloads.
        // First payload aggregates two skipped recordings into a single
        // entry of hits=2 with the latest skipReason.
        $bulkTracker = new OpenApiCoverageTracker();
        $bulkTracker->importStateOn([
            'version' => 1,
            'specs' => ['petstore-3.0' => ['GET /v1/pets' => [
                'requestReached' => false,
                'responses' => ['200:application/json' => ['state' => 'skipped', 'hits' => 2, 'skipReason' => 'reason-2']],
            ]]],
        ]);
        $bulkTracker->importStateOn([
            'version' => 1,
            'specs' => ['petstore-3.0' => ['GET /v1/pets' => [
                'requestReached' => false,
                'responses' => ['200:application/json' => ['state' => 'validated', 'hits' => 1, 'skipReason' => null]],
            ]]],
        ]);
        $bulk = $bulkTracker->exportStateOn();

        // The two paths must agree on the final reconciled state — pinned
        // here so a future tweak to one branch and not the other is caught.
        $this->assertSame($sequential, $bulk);
    }
}
