<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\PHPUnit\ConsoleCoverageRenderer;
use Studio\OpenApiContractTesting\PHPUnit\ConsoleOutput;

use function count;
use function substr_count;

class ConsoleCoverageRendererTest extends TestCase
{
    #[Test]
    public function render_returns_empty_string_for_empty_results(): void
    {
        $this->assertSame('', ConsoleCoverageRenderer::render([]));
    }

    #[Test]
    public function render_default_mode_shows_covered_list_and_uncovered_count(): void
    {
        $results = [
            'front' => self::coverageResult(
                covered: ['GET /v1/pets', 'POST /v1/pets'],
                uncovered: ['DELETE /v1/pets/{petId}', 'GET /v1/pets/{petId}'],
                total: 4,
            ),
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::DEFAULT);

        $this->assertStringContainsString('OpenAPI Contract Test Coverage', $output);
        $this->assertStringContainsString('[front] 2/4 endpoints (50%)', $output);
        $this->assertStringContainsString('Covered:', $output);
        $this->assertStringContainsString('  ✓ GET /v1/pets', $output);
        $this->assertStringContainsString('  ✓ POST /v1/pets', $output);
        $this->assertStringContainsString('Uncovered: 2 endpoints', $output);
        $this->assertStringNotContainsString('  ✗', $output);
        $this->assertStringNotContainsString('skipped-only', $output);
        $this->assertStringNotContainsString('⚠', $output);
    }

    #[Test]
    public function render_all_mode_shows_both_covered_and_uncovered_lists(): void
    {
        $results = [
            'front' => self::coverageResult(
                covered: ['GET /v1/pets', 'POST /v1/pets'],
                uncovered: ['DELETE /v1/pets/{petId}', 'GET /v1/pets/{petId}'],
                total: 4,
            ),
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::ALL);

        $this->assertStringContainsString('Covered:', $output);
        $this->assertStringContainsString('  ✓ GET /v1/pets', $output);
        $this->assertStringContainsString('  ✓ POST /v1/pets', $output);
        $this->assertStringContainsString('Uncovered:', $output);
        $this->assertStringContainsString('  ✗ DELETE /v1/pets/{petId}', $output);
        $this->assertStringContainsString('  ✗ GET /v1/pets/{petId}', $output);
        $this->assertStringNotContainsString('Uncovered: 2 endpoints', $output);
    }

    #[Test]
    public function render_uncovered_only_mode_shows_uncovered_list_and_covered_count(): void
    {
        $results = [
            'front' => self::coverageResult(
                covered: ['GET /v1/pets', 'POST /v1/pets'],
                uncovered: ['DELETE /v1/pets/{petId}', 'GET /v1/pets/{petId}'],
                total: 4,
            ),
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::UNCOVERED_ONLY);

        $this->assertStringContainsString('Covered: 2 endpoints', $output);
        $this->assertStringNotContainsString('  ✓', $output);
        $this->assertStringContainsString('Uncovered:', $output);
        $this->assertStringContainsString('  ✗ DELETE /v1/pets/{petId}', $output);
        $this->assertStringContainsString('  ✗ GET /v1/pets/{petId}', $output);
    }

    #[Test]
    public function render_full_coverage_default_mode_shows_no_uncovered_section(): void
    {
        $results = [
            'front' => self::coverageResult(
                covered: ['GET /v1/pets', 'POST /v1/pets'],
                uncovered: [],
                total: 2,
            ),
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::DEFAULT);

        $this->assertStringContainsString('[front] 2/2 endpoints (100%)', $output);
        $this->assertStringContainsString('  ✓ GET /v1/pets', $output);
        $this->assertStringNotContainsString('Uncovered', $output);
    }

    #[Test]
    public function render_full_coverage_all_mode_shows_no_uncovered_section(): void
    {
        $results = [
            'front' => self::coverageResult(
                covered: ['GET /v1/pets', 'POST /v1/pets'],
                uncovered: [],
                total: 2,
            ),
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::ALL);

        $this->assertStringContainsString('  ✓ GET /v1/pets', $output);
        $this->assertStringNotContainsString('Uncovered', $output);
    }

    #[Test]
    public function render_zero_coverage_default_mode_shows_uncovered_count(): void
    {
        $results = [
            'front' => self::coverageResult(
                covered: [],
                uncovered: ['GET /v1/pets', 'POST /v1/pets'],
                total: 2,
            ),
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::DEFAULT);

        $this->assertStringContainsString('[front] 0/2 endpoints (0%)', $output);
        $this->assertStringNotContainsString('Covered:', $output);
        $this->assertStringContainsString('Uncovered: 2 endpoints', $output);
    }

    #[Test]
    public function render_zero_coverage_uncovered_only_mode_shows_uncovered_list(): void
    {
        $results = [
            'front' => self::coverageResult(
                covered: [],
                uncovered: ['GET /v1/pets', 'POST /v1/pets'],
                total: 2,
            ),
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::UNCOVERED_ONLY);

        $this->assertStringContainsString('[front] 0/2 endpoints (0%)', $output);
        $this->assertStringNotContainsString('Covered:', $output);
        $this->assertStringContainsString('Uncovered:', $output);
        $this->assertStringContainsString('  ✗ GET /v1/pets', $output);
        $this->assertStringContainsString('  ✗ POST /v1/pets', $output);
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

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::DEFAULT);

        $this->assertStringContainsString('[front] 1/2 endpoints (50%)', $output);
        $this->assertStringContainsString('[admin] 1/1 endpoints (100%)', $output);
    }

    #[Test]
    public function render_defaults_to_default_mode(): void
    {
        $results = [
            'front' => self::coverageResult(
                covered: ['GET /v1/pets'],
                uncovered: ['POST /v1/pets'],
                total: 2,
            ),
        ];

        $withExplicitDefault = ConsoleCoverageRenderer::render($results, ConsoleOutput::DEFAULT);
        $withImplicitDefault = ConsoleCoverageRenderer::render($results);

        $this->assertSame($withExplicitDefault, $withImplicitDefault);
    }

    #[Test]
    public function render_header_and_separators_are_present(): void
    {
        $results = [
            'front' => self::coverageResult(
                covered: ['GET /v1/pets'],
                uncovered: [],
                total: 1,
            ),
        ];

        $output = ConsoleCoverageRenderer::render($results);

        $this->assertStringContainsString('OpenAPI Contract Test Coverage', $output);
        $this->assertStringContainsString('==================================================', $output);
        $this->assertStringContainsString('--------------------------------------------------', $output);
    }

    #[Test]
    public function render_zero_coverage_all_mode_shows_uncovered_list_only(): void
    {
        $results = [
            'front' => self::coverageResult(
                covered: [],
                uncovered: ['GET /v1/pets', 'POST /v1/pets'],
                total: 2,
            ),
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::ALL);

        $this->assertStringContainsString('[front] 0/2 endpoints (0%)', $output);
        $this->assertStringNotContainsString('Covered', $output);
        $this->assertStringContainsString('Uncovered:', $output);
        $this->assertStringContainsString('  ✗ GET /v1/pets', $output);
    }

    #[Test]
    public function render_full_coverage_uncovered_only_mode_shows_covered_count_only(): void
    {
        $results = [
            'front' => self::coverageResult(
                covered: ['GET /v1/pets', 'POST /v1/pets'],
                uncovered: [],
                total: 2,
            ),
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::UNCOVERED_ONLY);

        $this->assertStringContainsString('Covered: 2 endpoints', $output);
        $this->assertStringNotContainsString('  ✓', $output);
        $this->assertStringNotContainsString('Uncovered', $output);
    }

    #[Test]
    public function render_percentage_rounds_to_one_decimal_place(): void
    {
        $results = [
            'front' => self::coverageResult(
                covered: ['GET /v1/pets'],
                uncovered: ['POST /v1/pets', 'DELETE /v1/pets/{petId}'],
                total: 3,
            ),
        ];

        $output = ConsoleCoverageRenderer::render($results);

        $this->assertStringContainsString('[front] 1/3 endpoints (33.3%)', $output);
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

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::ALL);

        $this->assertStringContainsString('[empty] 0/0 endpoints (0%)', $output);
        $this->assertStringNotContainsString('Covered', $output);
        $this->assertStringNotContainsString('Uncovered', $output);
    }

    #[Test]
    public function header_appends_skipped_only_count_when_nonzero(): void
    {
        $results = [
            'front' => self::coverageResult(
                covered: ['GET /v1/pets', 'POST /v1/pets'],
                uncovered: ['DELETE /v1/pets/{petId}'],
                total: 3,
                skippedOnly: ['POST /v1/pets'],
            ),
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::DEFAULT);

        $this->assertStringContainsString('[front] 2/3 endpoints (66.7%), 1 skipped-only', $output);
    }

    #[Test]
    public function header_omits_skipped_only_when_zero(): void
    {
        $results = [
            'front' => self::coverageResult(
                covered: ['GET /v1/pets'],
                uncovered: [],
                total: 1,
            ),
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::DEFAULT);

        $this->assertStringContainsString('[front] 1/1 endpoints (100%)', $output);
        $this->assertStringNotContainsString('skipped-only', $output);
    }

    #[Test]
    public function all_mode_renders_warning_marker_for_skipped_only_rows(): void
    {
        $results = [
            'front' => self::coverageResult(
                covered: ['GET /v1/pets', 'POST /v1/pets'],
                uncovered: [],
                total: 2,
                skippedOnly: ['POST /v1/pets'],
            ),
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::ALL);

        $this->assertStringContainsString('  ✓ GET /v1/pets', $output);
        $this->assertStringContainsString('  ⚠ POST /v1/pets', $output);
        $this->assertStringNotContainsString('  ✓ POST /v1/pets', $output);
    }

    #[Test]
    public function default_mode_includes_legend_once_when_any_skipped_only(): void
    {
        $results = [
            'front' => self::coverageResult(
                covered: ['GET /v1/pets', 'POST /v1/pets'],
                uncovered: [],
                total: 2,
                skippedOnly: ['POST /v1/pets'],
            ),
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::DEFAULT);

        $this->assertStringContainsString('⚠ = response body validation skipped', $output);
        $this->assertSame(1, substr_count($output, '⚠ = response body validation skipped'));
    }

    #[Test]
    public function uncovered_only_mode_omits_legend_and_per_row_markers(): void
    {
        $results = [
            'front' => self::coverageResult(
                covered: ['GET /v1/pets', 'POST /v1/pets'],
                uncovered: ['DELETE /v1/pets/{petId}'],
                total: 3,
                skippedOnly: ['POST /v1/pets'],
            ),
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::UNCOVERED_ONLY);

        // Header still carries the skipped-only count.
        $this->assertStringContainsString(', 1 skipped-only', $output);
        // Per-row output and legend are suppressed in UNCOVERED_ONLY mode.
        $this->assertStringContainsString('Covered: 2 endpoints', $output);
        $this->assertStringNotContainsString('⚠', $output);
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
