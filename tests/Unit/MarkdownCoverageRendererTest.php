<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\PHPUnit\MarkdownCoverageRenderer;

use function explode;

class MarkdownCoverageRendererTest extends TestCase
{
    #[Test]
    public function render_returns_empty_string_for_empty_results(): void
    {
        $this->assertSame('', MarkdownCoverageRenderer::render([]));
    }

    #[Test]
    public function render_full_coverage_marks_all_endpoints_with_check(): void
    {
        $results = [
            'front' => self::coverage(
                endpoints: [
                    self::endpoint('GET /v1/pets', 'all-covered', responses: [
                        self::row('200', 'application/json', 'validated', hits: 3),
                    ], coveredResponseCount: 1, totalResponseCount: 1),
                ],
                endpointTotal: 1,
                endpointFullyCovered: 1,
                responseTotal: 1,
                responseCovered: 1,
            ),
        ];

        $output = MarkdownCoverageRenderer::render($results);

        $this->assertStringContainsString('### front — endpoints: 1/1 fully covered (100%)', $output);
        $this->assertStringContainsString('responses: 1/1 covered (100%)', $output);
        $this->assertStringContainsString('| :white_check_mark: | `GET /v1/pets` | 1/1 |', $output);
        $this->assertStringContainsString('| :white_check_mark: 200 | application/json | validated (3 hits) |', $output);
    }

    #[Test]
    public function render_partial_endpoint_uses_orange_diamond_marker(): void
    {
        $results = [
            'front' => self::coverage(
                endpoints: [
                    self::endpoint('POST /v1/pets', 'partial', responses: [
                        self::row('201', 'application/json', 'validated', hits: 1),
                        self::row('422', 'application/problem+json', 'uncovered'),
                    ], coveredResponseCount: 1, totalResponseCount: 2),
                ],
                endpointTotal: 1,
                endpointPartial: 1,
                responseTotal: 2,
                responseCovered: 1,
                responseUncovered: 1,
            ),
        ];

        $output = MarkdownCoverageRenderer::render($results);

        $this->assertStringContainsString('| :large_orange_diamond: | `POST /v1/pets` | 1/2 (1 uncovered) |', $output);
        $this->assertStringContainsString('| :x: 422 | application/problem+json | uncovered |', $output);
    }

    #[Test]
    public function render_skipped_response_uses_warning_marker_with_reason(): void
    {
        $results = [
            'front' => self::coverage(
                endpoints: [
                    self::endpoint('DELETE /v1/pets/{petId}', 'partial', responses: [
                        self::row('204', '*', 'validated', hits: 2),
                        self::row('5XX', '*', 'skipped', hits: 1, skipReason: 'status 503 matched skip pattern 5\d\d'),
                    ], coveredResponseCount: 1, skippedResponseCount: 1, totalResponseCount: 2),
                ],
                endpointTotal: 1,
                endpointPartial: 1,
                responseTotal: 2,
                responseCovered: 1,
                responseSkipped: 1,
            ),
        ];

        $output = MarkdownCoverageRenderer::render($results);

        $this->assertStringContainsString('| :warning: 5XX | * | skipped (status 503 matched skip pattern 5\d\d) |', $output);
        $this->assertStringContainsString('1/2 (1 skipped)', $output);
    }

    #[Test]
    public function render_uncovered_endpoint_uses_x_marker(): void
    {
        $results = [
            'front' => self::coverage(
                endpoints: [
                    self::endpoint('GET /v1/health', 'uncovered', responses: [
                        self::row('200', 'application/json', 'uncovered'),
                    ], totalResponseCount: 1),
                ],
                endpointTotal: 1,
                endpointUncovered: 1,
                responseTotal: 1,
                responseUncovered: 1,
            ),
        ];

        $output = MarkdownCoverageRenderer::render($results);

        $this->assertStringContainsString('| :x: | `GET /v1/health` | 0/1 (1 uncovered) |', $output);
    }

    #[Test]
    public function render_request_only_endpoint_uses_info_marker(): void
    {
        $results = [
            'front' => self::coverage(
                endpoints: [
                    self::endpoint(
                        'GET /v1/loose',
                        'request-only',
                        requestReached: true,
                    ),
                ],
                endpointTotal: 1,
                endpointRequestOnly: 1,
            ),
        ];

        $output = MarkdownCoverageRenderer::render($results);

        $this->assertStringContainsString('| :information_source: | `GET /v1/loose` | request only |', $output);
        $this->assertStringContainsString('_request reached, no response definitions in spec_', $output);
    }

    #[Test]
    public function render_unexpected_observations_appear_in_detail_section(): void
    {
        $results = [
            'front' => self::coverage(
                endpoints: [
                    self::endpoint('GET /v1/pets', 'partial', responses: [
                        self::row('200', 'application/json', 'validated', hits: 1),
                    ], coveredResponseCount: 1, totalResponseCount: 1, unexpectedObservations: [
                        ['statusKey' => '418', 'contentTypeKey' => 'application/json'],
                    ]),
                ],
                endpointTotal: 1,
                endpointPartial: 1,
                responseTotal: 1,
                responseCovered: 1,
            ),
        ];

        $output = MarkdownCoverageRenderer::render($results);

        $this->assertStringContainsString('Unexpected observations', $output);
        $this->assertStringContainsString('`418` `application/json`', $output);
    }

    #[Test]
    public function render_includes_operation_id_when_present(): void
    {
        $results = [
            'front' => self::coverage(
                endpoints: [
                    self::endpoint(
                        'GET /v1/pets',
                        'all-covered',
                        operationId: 'listPets',
                        responses: [self::row('200', 'application/json', 'validated', hits: 1)],
                        coveredResponseCount: 1,
                        totalResponseCount: 1,
                    ),
                ],
                endpointTotal: 1,
                endpointFullyCovered: 1,
                responseTotal: 1,
                responseCovered: 1,
            ),
        ];

        $output = MarkdownCoverageRenderer::render($results);

        $this->assertStringContainsString('#### `GET /v1/pets` (listPets)', $output);
    }

    /**
     * @param list<array{endpoint: string, method: string, path: string, operationId: ?string, state: string, requestReached: bool, responses: list<array{statusKey: string, contentTypeKey: string, state: string, hits: int, skipReason: ?string}>, coveredResponseCount: int, skippedResponseCount: int, totalResponseCount: int, unexpectedObservations: list<array{statusKey: string, contentTypeKey: string}>}> $endpoints
     *
     * @return array{endpoints: list<array{endpoint: string, method: string, path: string, operationId: ?string, state: string, requestReached: bool, responses: list<array{statusKey: string, contentTypeKey: string, state: string, hits: int, skipReason: ?string}>, coveredResponseCount: int, skippedResponseCount: int, totalResponseCount: int, unexpectedObservations: list<array{statusKey: string, contentTypeKey: string}>}>, endpointTotal: int, endpointFullyCovered: int, endpointPartial: int, endpointUncovered: int, endpointRequestOnly: int, responseTotal: int, responseCovered: int, responseSkipped: int, responseUncovered: int}
     */
    private static function coverage(
        array $endpoints,
        int $endpointTotal = 0,
        int $endpointFullyCovered = 0,
        int $endpointPartial = 0,
        int $endpointUncovered = 0,
        int $endpointRequestOnly = 0,
        int $responseTotal = 0,
        int $responseCovered = 0,
        int $responseSkipped = 0,
        int $responseUncovered = 0,
    ): array {
        return [
            'endpoints' => $endpoints,
            'endpointTotal' => $endpointTotal,
            'endpointFullyCovered' => $endpointFullyCovered,
            'endpointPartial' => $endpointPartial,
            'endpointUncovered' => $endpointUncovered,
            'endpointRequestOnly' => $endpointRequestOnly,
            'responseTotal' => $responseTotal,
            'responseCovered' => $responseCovered,
            'responseSkipped' => $responseSkipped,
            'responseUncovered' => $responseUncovered,
        ];
    }

    /**
     * @param list<array{statusKey: string, contentTypeKey: string, state: string, hits: int, skipReason: ?string}> $responses
     * @param list<array{statusKey: string, contentTypeKey: string}> $unexpectedObservations
     *
     * @return array{endpoint: string, method: string, path: string, operationId: ?string, state: string, requestReached: bool, responses: list<array{statusKey: string, contentTypeKey: string, state: string, hits: int, skipReason: ?string}>, coveredResponseCount: int, skippedResponseCount: int, totalResponseCount: int, unexpectedObservations: list<array{statusKey: string, contentTypeKey: string}>}
     */
    private static function endpoint(
        string $endpoint,
        string $state,
        bool $requestReached = false,
        ?string $operationId = null,
        array $responses = [],
        int $coveredResponseCount = 0,
        int $skippedResponseCount = 0,
        int $totalResponseCount = 0,
        array $unexpectedObservations = [],
    ): array {
        [$method, $path] = explode(' ', $endpoint, 2);

        return [
            'endpoint' => $endpoint,
            'method' => $method,
            'path' => $path,
            'operationId' => $operationId,
            'state' => $state,
            'requestReached' => $requestReached,
            'responses' => $responses,
            'coveredResponseCount' => $coveredResponseCount,
            'skippedResponseCount' => $skippedResponseCount,
            'totalResponseCount' => $totalResponseCount,
            'unexpectedObservations' => $unexpectedObservations,
        ];
    }

    /**
     * @return array{statusKey: string, contentTypeKey: string, state: string, hits: int, skipReason: ?string}
     */
    private static function row(
        string $statusKey,
        string $contentTypeKey,
        string $state,
        int $hits = 0,
        ?string $skipReason = null,
    ): array {
        return [
            'statusKey' => $statusKey,
            'contentTypeKey' => $contentTypeKey,
            'state' => $state,
            'hits' => $hits,
            'skipReason' => $skipReason,
        ];
    }
}
