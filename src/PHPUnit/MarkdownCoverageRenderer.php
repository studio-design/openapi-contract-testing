<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\PHPUnit;

use function array_flip;
use function count;
use function implode;
use function round;

/**
 * @phpstan-type CoverageResult array{
 *     covered: string[],
 *     uncovered: string[],
 *     total: int,
 *     coveredCount: int,
 *     skippedOnly: string[],
 *     skippedOnlyCount: int,
 * }
 */
final class MarkdownCoverageRenderer
{
    private const SKIPPED_ONLY_NOTE = '> :warning: response body validation skipped (e.g. 5xx default skip) — endpoint was exercised but its body was never checked against the schema.';

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

            if ($result['skippedOnlyCount'] > 0) {
                $lines[] = self::SKIPPED_ONLY_NOTE;
                $lines[] = '';
            }

            if ($result['covered'] !== []) {
                $skipSet = array_flip($result['skippedOnly']);
                $lines[] = '| Status | Endpoint |';
                $lines[] = '|--------|----------|';
                foreach ($result['covered'] as $endpoint) {
                    $marker = isset($skipSet[$endpoint]) ? ':warning:' : ':white_check_mark:';
                    $lines[] = "| {$marker} | `{$endpoint}` |";
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
