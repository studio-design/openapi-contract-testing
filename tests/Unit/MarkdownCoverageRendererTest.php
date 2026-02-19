<?php

declare(strict_types=1);

namespace Wadakatu\OpenApiContractTesting\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wadakatu\OpenApiContractTesting\PHPUnit\MarkdownCoverageRenderer;

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
            'front' => [
                'covered' => ['GET /v1/pets', 'POST /v1/pets'],
                'uncovered' => [],
                'total' => 2,
                'coveredCount' => 2,
            ],
        ];

        $output = MarkdownCoverageRenderer::render($results);

        $this->assertStringContainsString('### front — 2/2 endpoints (100%)', $output);
        $this->assertStringContainsString('| :white_check_mark: | `GET /v1/pets` |', $output);
        $this->assertStringContainsString('| :white_check_mark: | `POST /v1/pets` |', $output);
        $this->assertStringNotContainsString('<details>', $output);
    }

    #[Test]
    public function render_partial_coverage_has_table_and_details(): void
    {
        $results = [
            'front' => [
                'covered' => ['GET /v1/pets', 'POST /v1/pets'],
                'uncovered' => ['DELETE /v1/pets/{petId}', 'GET /v1/pets/{petId}'],
                'total' => 4,
                'coveredCount' => 2,
            ],
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
            'front' => [
                'covered' => [],
                'uncovered' => ['GET /v1/pets', 'POST /v1/pets'],
                'total' => 2,
                'coveredCount' => 0,
            ],
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

        $output = MarkdownCoverageRenderer::render($results);

        $this->assertStringContainsString('### front — 1/2 endpoints (50%)', $output);
        $this->assertStringContainsString('### admin — 1/1 endpoints (100%)', $output);
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

        $output = MarkdownCoverageRenderer::render($results);

        $this->assertStringContainsString('### empty — 0/0 endpoints (0%)', $output);
        $this->assertStringNotContainsString(':white_check_mark:', $output);
        $this->assertStringNotContainsString('<details>', $output);
    }
}
