<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Coverage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Coverage\EndpointCoverageState;
use Studio\OpenApiContractTesting\Coverage\HtmlCoverageRenderer;
use Studio\OpenApiContractTesting\Coverage\ResponseCoverageState;

use function explode;
use function substr_count;

class HtmlCoverageRendererTest extends TestCase
{
    #[Test]
    public function render_returns_empty_string_for_empty_results(): void
    {
        // Matches the contract of MarkdownCoverageRenderer / JUnitCoverageRenderer /
        // JsonCoverageRenderer — callers short-circuit a no-coverage run.
        $this->assertSame('', HtmlCoverageRenderer::render([]));
    }

    #[Test]
    public function render_emits_html5_doctype_and_utf8_meta(): void
    {
        $html = $this->renderOneValidated();

        $this->assertStringStartsWith('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<meta charset="UTF-8">', $html);
        $this->assertStringContainsString('<title>', $html);
    }

    #[Test]
    public function render_inlines_all_css_with_no_external_resources(): void
    {
        // The HTML must be a single self-contained file — no external CSS/JS
        // links — so CI artifact upload + offline review work without
        // additional configuration.
        $html = $this->renderOneValidated();

        $this->assertStringContainsString('<style>', $html);
        $this->assertStringNotContainsString('<link', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('href="http', $html);
        $this->assertStringNotContainsString('src="http', $html);
    }

    #[Test]
    public function render_escapes_special_characters_in_paths_and_operation_ids(): void
    {
        // htmlspecialchars(ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') must be
        // applied so a hostile spec value cannot inject markup. The path
        // template braces and ampersands are realistic OpenAPI content.
        $html = $this->renderOne(
            'front',
            self::endpoint('GET /v1/pets/<script>alert(1)</script>', 'uncovered', responses: [
                self::row('200', 'application/json', 'uncovered'),
            ], totalResponseCount: 1),
            endpointTotal: 1,
            endpointUncovered: 1,
            responseTotal: 1,
            responseUncovered: 1,
        );

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    #[Test]
    public function render_escapes_skip_reasons(): void
    {
        $html = $this->renderOne(
            'front',
            self::endpoint('DELETE /v1/pets/{petId}', 'partial', responses: [
                self::row('503', 'application/json', 'skipped', hits: 1, skipReason: 'pattern <foo>&bar'),
            ], skippedResponseCount: 1, totalResponseCount: 1),
            endpointTotal: 1,
            endpointPartial: 1,
            responseTotal: 1,
            responseSkipped: 1,
        );

        $this->assertStringNotContainsString('<foo>&bar', $html);
        $this->assertStringContainsString('&lt;foo&gt;&amp;bar', $html);
    }

    #[Test]
    public function render_includes_aggregate_summary_with_counts(): void
    {
        // The page header must surface aggregate counts so a reviewer can
        // gauge coverage health at-a-glance without scrolling per-spec
        // sections.
        $html = $this->renderOneValidated();

        $this->assertStringContainsString('1', $html);
        $this->assertStringContainsString('endpoints', $html);
        $this->assertStringContainsString('responses', $html);
    }

    #[Test]
    public function render_emits_all_four_endpoint_state_markers(): void
    {
        // The four state CSS classes must be distinct so styling differentiates
        // them. (Don't depend on exact class names; just verify they exist as
        // distinct emitted tokens.)
        $results = [
            'front' => self::coverage(
                endpoints: [
                    self::endpoint('GET /a', 'all-covered', responses: [
                        self::row('200', 'application/json', 'validated', hits: 1),
                    ], coveredResponseCount: 1, totalResponseCount: 1),
                    self::endpoint('GET /b', 'partial', responses: [
                        self::row('200', 'application/json', 'validated', hits: 1),
                        self::row('500', 'application/json', 'uncovered'),
                    ], coveredResponseCount: 1, totalResponseCount: 2),
                    self::endpoint('GET /c', 'uncovered', responses: [
                        self::row('200', 'application/json', 'uncovered'),
                    ], totalResponseCount: 1),
                    self::endpoint('GET /d', 'request-only', requestReached: true, unexpectedObservations: [
                        ['statusKey' => '418', 'contentTypeKey' => 'application/json'],
                    ]),
                ],
                endpointTotal: 4,
                endpointFullyCovered: 1,
                endpointPartial: 1,
                endpointUncovered: 1,
                endpointRequestOnly: 1,
                responseTotal: 3,
                responseCovered: 2,
                responseUncovered: 1,
            ),
        ];

        $html = HtmlCoverageRenderer::render($results);

        $this->assertStringContainsString('all-covered', $html);
        $this->assertStringContainsString('partial', $html);
        $this->assertStringContainsString('uncovered', $html);
        $this->assertStringContainsString('request-only', $html);
    }

    #[Test]
    public function render_emits_details_summary_per_spec(): void
    {
        // <details>/<summary> gives reviewers a collapsible per-endpoint detail
        // view without needing JavaScript. Required for the "single
        // self-contained HTML, no JS" contract.
        $html = $this->renderOneValidated();

        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('<summary', $html);
    }

    #[Test]
    public function render_emits_anchor_targets_for_endpoint_navigation(): void
    {
        // Each endpoint section must have an id attribute so the in-page
        // endpoint list can link directly to it.
        $html = $this->renderOneValidated();

        $this->assertMatchesRegularExpression('/id="endpoint-[^"]+"/', $html);
        $this->assertMatchesRegularExpression('/href="#endpoint-[^"]+"/', $html);
    }

    #[Test]
    public function render_emits_section_per_spec(): void
    {
        // Multi-spec runs must produce one section per spec rather than
        // merging endpoints under a single header.
        $results = [
            'spec-a' => self::coverage(
                endpoints: [
                    self::endpoint('GET /a/pets', 'all-covered', responses: [
                        self::row('200', 'application/json', 'validated', hits: 1),
                    ], coveredResponseCount: 1, totalResponseCount: 1),
                ],
                endpointTotal: 1,
                endpointFullyCovered: 1,
                responseTotal: 1,
                responseCovered: 1,
            ),
            'spec-b' => self::coverage(
                endpoints: [
                    self::endpoint('GET /b/widgets', 'all-covered', responses: [
                        self::row('200', 'application/json', 'validated', hits: 1),
                    ], coveredResponseCount: 1, totalResponseCount: 1),
                ],
                endpointTotal: 1,
                endpointFullyCovered: 1,
                responseTotal: 1,
                responseCovered: 1,
            ),
        ];

        $html = HtmlCoverageRenderer::render($results);

        $this->assertStringContainsString('spec-a', $html);
        $this->assertStringContainsString('spec-b', $html);
        $this->assertStringContainsString('GET /a/pets', $html);
        $this->assertStringContainsString('GET /b/widgets', $html);
        // At minimum one <details> per spec.
        $this->assertGreaterThanOrEqual(2, substr_count($html, '<details'));
    }

    #[Test]
    public function render_emits_unexpected_observations(): void
    {
        $html = $this->renderOne(
            'front',
            self::endpoint('POST /v1/pets', 'partial', responses: [
                self::row('201', 'application/json', 'validated', hits: 1),
            ], coveredResponseCount: 1, totalResponseCount: 1, unexpectedObservations: [
                ['statusKey' => '418', 'contentTypeKey' => 'application/json'],
            ]),
            endpointTotal: 1,
            endpointFullyCovered: 1,
            responseTotal: 1,
            responseCovered: 1,
        );

        $this->assertStringContainsString('418', $html);
        $this->assertStringContainsString('application/json', $html);
    }

    #[Test]
    public function render_ends_with_closing_html_tag(): void
    {
        $html = $this->renderOneValidated();

        $this->assertStringEndsWith("</html>\n", $html);
    }

    /**
     * @param list<array{endpoint: string, method: string, path: string, operationId: ?string, state: EndpointCoverageState, requestReached: bool, responses: list<array{statusKey: string, contentTypeKey: string, state: ResponseCoverageState, hits: int, skipReason: ?string}>, coveredResponseCount: int, skippedResponseCount: int, totalResponseCount: int, unexpectedObservations: list<array{statusKey: string, contentTypeKey: string}>}> $endpoints
     *
     * @return array{endpoints: list<array{endpoint: string, method: string, path: string, operationId: ?string, state: EndpointCoverageState, requestReached: bool, responses: list<array{statusKey: string, contentTypeKey: string, state: ResponseCoverageState, hits: int, skipReason: ?string}>, coveredResponseCount: int, skippedResponseCount: int, totalResponseCount: int, unexpectedObservations: list<array{statusKey: string, contentTypeKey: string}>}>, endpointTotal: int, endpointFullyCovered: int, endpointPartial: int, endpointUncovered: int, endpointRequestOnly: int, responseTotal: int, responseCovered: int, responseSkipped: int, responseUncovered: int}
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
     * @param list<array{statusKey: string, contentTypeKey: string, state: ResponseCoverageState, hits: int, skipReason: ?string}> $responses
     * @param list<array{statusKey: string, contentTypeKey: string}> $unexpectedObservations
     *
     * @return array{endpoint: string, method: string, path: string, operationId: ?string, state: EndpointCoverageState, requestReached: bool, responses: list<array{statusKey: string, contentTypeKey: string, state: ResponseCoverageState, hits: int, skipReason: ?string}>, coveredResponseCount: int, skippedResponseCount: int, totalResponseCount: int, unexpectedObservations: list<array{statusKey: string, contentTypeKey: string}>}
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
            'state' => EndpointCoverageState::from($state),
            'requestReached' => $requestReached,
            'responses' => $responses,
            'coveredResponseCount' => $coveredResponseCount,
            'skippedResponseCount' => $skippedResponseCount,
            'totalResponseCount' => $totalResponseCount,
            'unexpectedObservations' => $unexpectedObservations,
        ];
    }

    /**
     * @return array{statusKey: string, contentTypeKey: string, state: ResponseCoverageState, hits: int, skipReason: ?string}
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
            'state' => ResponseCoverageState::from($state),
            'hits' => $hits,
            'skipReason' => $skipReason,
        ];
    }

    /**
     * @param array<string, mixed> $endpoint
     */
    private function renderOne(
        string $specName,
        array $endpoint,
        int $endpointTotal = 0,
        int $endpointFullyCovered = 0,
        int $endpointPartial = 0,
        int $endpointUncovered = 0,
        int $endpointRequestOnly = 0,
        int $responseTotal = 0,
        int $responseCovered = 0,
        int $responseSkipped = 0,
        int $responseUncovered = 0,
    ): string {
        $results = [
            $specName => self::coverage(
                endpoints: [$endpoint],
                endpointTotal: $endpointTotal,
                endpointFullyCovered: $endpointFullyCovered,
                endpointPartial: $endpointPartial,
                endpointUncovered: $endpointUncovered,
                endpointRequestOnly: $endpointRequestOnly,
                responseTotal: $responseTotal,
                responseCovered: $responseCovered,
                responseSkipped: $responseSkipped,
                responseUncovered: $responseUncovered,
            ),
        ];

        return HtmlCoverageRenderer::render($results);
    }

    private function renderOneValidated(): string
    {
        return $this->renderOne(
            'front',
            self::endpoint(
                'GET /v1/pets',
                'all-covered',
                requestReached: true,
                operationId: 'listPets',
                responses: [
                    self::row('200', 'application/json', 'validated', hits: 3),
                ],
                coveredResponseCount: 1,
                totalResponseCount: 1,
            ),
            endpointTotal: 1,
            endpointFullyCovered: 1,
            responseTotal: 1,
            responseCovered: 1,
        );
    }
}
