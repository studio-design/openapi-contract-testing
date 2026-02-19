<?php

declare(strict_types=1);

namespace Wadakatu\OpenApiContractTesting\PHPUnit;

use function count;
use function implode;
use function round;

/**
 * @phpstan-type CoverageResult array{covered: string[], uncovered: string[], total: int, coveredCount: int}
 */
final class MarkdownCoverageRenderer
{
    /**
     * @param array<string, CoverageResult> $results
     */
    public static function render(array $results): string
    {
        if ($results === []) {
            return '';
        }

        $lines = ['## OpenAPI Contract Test Coverage', ''];

        foreach ($results as $specName => $result) {
            $total = $result['total'];
            $coveredCount = $result['coveredCount'];
            $percentage = $total > 0
                ? round($coveredCount / $total * 100, 1)
                : 0;

            $lines[] = "### {$specName} — {$coveredCount}/{$total} endpoints ({$percentage}%)";
            $lines[] = '';

            if ($result['covered'] !== []) {
                $lines[] = '| Status | Endpoint |';
                $lines[] = '|--------|----------|';
                foreach ($result['covered'] as $endpoint) {
                    $lines[] = "| :white_check_mark: | `{$endpoint}` |";
                }
                $lines[] = '';
            }

            $uncoveredCount = count($result['uncovered']);
            if ($uncoveredCount > 0) {
                $lines[] = '<details>';
                $lines[] = "<summary>{$uncoveredCount} uncovered endpoints</summary>";
                $lines[] = '';
                $lines[] = '| Endpoint |';
                $lines[] = '|----------|';
                foreach ($result['uncovered'] as $endpoint) {
                    $lines[] = "| `{$endpoint}` |";
                }
                $lines[] = '';
                $lines[] = '</details>';
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }
}
