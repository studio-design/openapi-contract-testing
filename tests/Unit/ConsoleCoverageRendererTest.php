<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\PHPUnit\ConsoleCoverageRenderer;
use Studio\OpenApiContractTesting\PHPUnit\ConsoleOutput;

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
            'front' => [
                'covered' => ['GET /v1/pets', 'POST /v1/pets'],
                'uncovered' => ['DELETE /v1/pets/{petId}', 'GET /v1/pets/{petId}'],
                'total' => 4,
                'coveredCount' => 2,
            ],
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::DEFAULT);

        $this->assertStringContainsString('OpenAPI Contract Test Coverage', $output);
        $this->assertStringContainsString('[front] 2/4 endpoints (50%)', $output);
        $this->assertStringContainsString('Covered:', $output);
        $this->assertStringContainsString('  ✓ GET /v1/pets', $output);
        $this->assertStringContainsString('  ✓ POST /v1/pets', $output);
        $this->assertStringContainsString('Uncovered: 2 endpoints', $output);
        $this->assertStringNotContainsString('  ✗', $output);
    }

    #[Test]
    public function render_all_mode_shows_both_covered_and_uncovered_lists(): void
    {
        $results = [
            'front' => [
                'covered' => ['GET /v1/pets', 'POST /v1/pets'],
                'uncovered' => ['DELETE /v1/pets/{petId}', 'GET /v1/pets/{petId}'],
                'total' => 4,
                'coveredCount' => 2,
            ],
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
            'front' => [
                'covered' => ['GET /v1/pets', 'POST /v1/pets'],
                'uncovered' => ['DELETE /v1/pets/{petId}', 'GET /v1/pets/{petId}'],
                'total' => 4,
                'coveredCount' => 2,
            ],
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
            'front' => [
                'covered' => ['GET /v1/pets', 'POST /v1/pets'],
                'uncovered' => [],
                'total' => 2,
                'coveredCount' => 2,
            ],
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
            'front' => [
                'covered' => ['GET /v1/pets', 'POST /v1/pets'],
                'uncovered' => [],
                'total' => 2,
                'coveredCount' => 2,
            ],
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::ALL);

        $this->assertStringContainsString('  ✓ GET /v1/pets', $output);
        $this->assertStringNotContainsString('Uncovered', $output);
    }

    #[Test]
    public function render_zero_coverage_default_mode_shows_uncovered_count(): void
    {
        $results = [
            'front' => [
                'covered' => [],
                'uncovered' => ['GET /v1/pets', 'POST /v1/pets'],
                'total' => 2,
                'coveredCount' => 0,
            ],
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
            'front' => [
                'covered' => [],
                'uncovered' => ['GET /v1/pets', 'POST /v1/pets'],
                'total' => 2,
                'coveredCount' => 0,
            ],
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
            'front' => [
                'covered' => ['GET /v1/pets'],
                'uncovered' => ['POST /v1/pets'],
                'total' => 2,
                'coveredCount' => 1,
            ],
            'admin' => [
                'covered' => ['GET /v1/users'],
                'uncovered' => [],
                'total' => 1,
                'coveredCount' => 1,
            ],
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::DEFAULT);

        $this->assertStringContainsString('[front] 1/2 endpoints (50%)', $output);
        $this->assertStringContainsString('[admin] 1/1 endpoints (100%)', $output);
    }

    #[Test]
    public function render_defaults_to_default_mode(): void
    {
        $results = [
            'front' => [
                'covered' => ['GET /v1/pets'],
                'uncovered' => ['POST /v1/pets'],
                'total' => 2,
                'coveredCount' => 1,
            ],
        ];

        $withExplicitDefault = ConsoleCoverageRenderer::render($results, ConsoleOutput::DEFAULT);
        $withImplicitDefault = ConsoleCoverageRenderer::render($results);

        $this->assertSame($withExplicitDefault, $withImplicitDefault);
    }

    #[Test]
    public function render_header_and_separators_are_present(): void
    {
        $results = [
            'front' => [
                'covered' => ['GET /v1/pets'],
                'uncovered' => [],
                'total' => 1,
                'coveredCount' => 1,
            ],
        ];

        $output = ConsoleCoverageRenderer::render($results);

        $this->assertStringContainsString('OpenAPI Contract Test Coverage', $output);
        $this->assertStringContainsString('==================================================', $output);
        $this->assertStringContainsString('--------------------------------------------------', $output);
    }

    #[Test]
    public function render_spec_with_zero_endpoints(): void
    {
        $results = [
            'empty' => [
                'covered' => [],
                'uncovered' => [],
                'total' => 0,
                'coveredCount' => 0,
            ],
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::ALL);

        $this->assertStringContainsString('[empty] 0/0 endpoints (0%)', $output);
        $this->assertStringNotContainsString('Covered', $output);
        $this->assertStringNotContainsString('Uncovered', $output);
    }
}
