<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\PHPUnit\MarkdownCoverageRenderer;

use function count;
use function substr_count;

class MarkdownCoverageRendererTest extends TestCase
{
    #[Test]
    public function render_returns_empty_string_for_empty_results(): void
    {
        $this->assertSame('', MarkdownCoverageRenderer::render([]));
    }

    #[Test]
    public function render_full_coverage_has_no_details_block(): void
    {
        $results = [
            'front' => self::coverageResult(
                covered: ['GET /v1/pets', 'POST /v1/pets'],
                uncovered: [],
                total: 2,
            ),
        ];

        $output = MarkdownCoverageRenderer::render($results);

        $this->assertStringContainsString('### front — 2/2 endpoints (100%)', $output);
        $this->assertStringContainsString('| :white_check_mark: | `GET /v1/pets` |', $output);
        $this->assertStringContainsString('| :white_check_mark: | `POST /v1/pets` |', $output);
        $this->assertStringNotContainsString('<details>', $output);
        $this->assertStringNotContainsString(':warning:', $output);
    }

    #[Test]
    public function render_partial_coverage_has_table_and_details(): void
    {
        $results = [
            'front' => self::coverageResult(
                covered: ['GET /v1/pets', 'POST /v1/pets'],
                uncovered: ['DELETE /v1/pets/{petId}', 'GET /v1/pets/{petId}'],
                total: 4,
            ),
        ];

        $output = MarkdownCoverageRenderer::render($results);

        $this->assertStringContainsString('### front — 2/4 endpoints (50%)', $output);
        $this->assertStringContainsString('| :white_check_mark: | `GET /v1/pets` |', $output);
        $this->assertStringContainsString('<details>', $output);
        $this->assertStringContainsString('<summary>2 uncovered endpoints</summary>', $output);
        $this->assertStringContainsString('| `DELETE /v1/pets/{petId}` |', $output);
        $this->assertStringContainsString('| `GET /v1/pets/{petId}` |', $output);
    }

    #[Test]
    public function render_zero_coverage_has_only_details(): void
    {
        $results = [
            'front' => self::coverageResult(
                covered: [],
                uncovered: ['GET /v1/pets', 'POST /v1/pets'],
                total: 2,
            ),
        ];

        $output = MarkdownCoverageRenderer::render($results);

        $this->assertStringContainsString('### front — 0/2 endpoints (0%)', $output);
        $this->assertStringNotContainsString(':white_check_mark:', $output);
        $this->assertStringContainsString('<details>', $output);
        $this->assertStringContainsString('<summary>2 uncovered endpoints</summary>', $output);
    }

    #[Test]
    public function render_multiple_specs(): void
    {
        $results = [
            'front' => self::coverageResult(
                covered: ['GET /v1/pets'],
                uncovered: ['POST /v1/pets'],
                total: 2,
            ),
            'admin' => self::coverageResult(
                covered: ['GET /v1/users'],
                uncovered: [],
                total: 1,
            ),
        ];

        $output = MarkdownCoverageRenderer::render($results);

        $this->assertStringContainsString('### front — 1/2 endpoints (50%)', $output);
        $this->assertStringContainsString('### admin — 1/1 endpoints (100%)', $output);
    }

    #[Test]
    public function render_spec_with_zero_endpoints(): void
    {
        $results = [
            'empty' => self::coverageResult(
                covered: [],
                uncovered: [],
                total: 0,
            ),
        ];

        $output = MarkdownCoverageRenderer::render($results);

        $this->assertStringContainsString('### empty — 0/0 endpoints (0%)', $output);
        $this->assertStringNotContainsString(':white_check_mark:', $output);
        $this->assertStringNotContainsString('<details>', $output);
    }

    #[Test]
    public function covered_table_uses_warning_emoji_for_skipped_only(): void
    {
        $results = [
            'front' => self::coverageResult(
                covered: ['GET /v1/pets', 'POST /v1/pets'],
                uncovered: [],
                total: 2,
                skippedOnly: ['POST /v1/pets'],
            ),
        ];

        $output = MarkdownCoverageRenderer::render($results);

        $this->assertStringContainsString('| :white_check_mark: | `GET /v1/pets` |', $output);
        $this->assertStringContainsString('| :warning: | `POST /v1/pets` |', $output);
        $this->assertStringNotContainsString('| :white_check_mark: | `POST /v1/pets` |', $output);
    }

    #[Test]
    public function renders_skipped_only_note_under_heading_when_present(): void
    {
        $results = [
            'front' => self::coverageResult(
                covered: ['GET /v1/pets', 'POST /v1/pets'],
                uncovered: [],
                total: 2,
                skippedOnly: ['POST /v1/pets'],
            ),
        ];

        $output = MarkdownCoverageRenderer::render($results);

        $this->assertStringContainsString('> :warning: response body validation skipped', $output);
        $this->assertSame(1, substr_count($output, '> :warning:'));
    }

    #[Test]
    public function each_spec_with_skipped_only_gets_its_own_note(): void
    {
        // Guards against a regression where the note were hoisted outside
        // the per-spec loop (producing one note for the whole report) or
        // duplicated (producing two notes for one spec).
        $results = [
            'front' => self::coverageResult(
                covered: ['GET /v1/pets'],
                uncovered: [],
                total: 1,
                skippedOnly: ['GET /v1/pets'],
            ),
            'admin' => self::coverageResult(
                covered: ['GET /v1/users'],
                uncovered: [],
                total: 1,
                skippedOnly: ['GET /v1/users'],
            ),
        ];

        $output = MarkdownCoverageRenderer::render($results);

        $this->assertSame(2, substr_count($output, '> :warning:'));
    }

    #[Test]
    public function omits_note_when_no_skipped_only(): void
    {
        $results = [
            'front' => self::coverageResult(
                covered: ['GET /v1/pets'],
                uncovered: [],
                total: 1,
            ),
        ];

        $output = MarkdownCoverageRenderer::render($results);

        $this->assertStringNotContainsString(':warning:', $output);
    }

    /**
     * @param string[] $covered
     * @param string[] $uncovered
     * @param null|string[] $skippedOnly
     *
     * @return array{
     *     covered: string[],
     *     uncovered: string[],
     *     total: int,
     *     coveredCount: int,
     *     skippedOnly: string[],
     *     skippedOnlyCount: int,
     * }
     */
    private static function coverageResult(
        array $covered,
        array $uncovered,
        int $total,
        ?array $skippedOnly = null,
    ): array {
        $skippedOnly ??= [];

        return [
            'covered' => $covered,
            'uncovered' => $uncovered,
            'total' => $total,
            'coveredCount' => count($covered),
            'skippedOnly' => $skippedOnly,
            'skippedOnlyCount' => count($skippedOnly),
        ];
    }
}
