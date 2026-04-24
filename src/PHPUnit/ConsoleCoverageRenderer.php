<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\PHPUnit;

use Studio\OpenApiContractTesting\OpenApiCoverageTracker;

use function array_flip;
use function count;
use function round;
use function str_repeat;

/**
 * @phpstan-import-type CoverageResult from OpenApiCoverageTracker
 */
final class ConsoleCoverageRenderer
{
    private const SKIPPED_ONLY_LEGEND = '  ⚠ = response body validation skipped (e.g. 5xx default skip)';

    /**
     * @param array<string, CoverageResult> $results
     */
    public static function render(array $results, ConsoleOutput $consoleOutput = ConsoleOutput::DEFAULT): string
    {
        if ($results === []) {
            return '';
        }

        $output = "\n\n";
        $output .= "OpenAPI Contract Test Coverage\n";
        $output .= str_repeat('=', 50) . "\n";

        foreach ($results as $spec => $result) {
            $percentage = self::percentage($result['coveredCount'], $result['total']);
            $skippedTag = $result['skippedOnlyCount'] > 0
                ? ", {$result['skippedOnlyCount']} skipped-only"
                : '';

            $output .= "\n[{$spec}] {$result['coveredCount']}/{$result['total']} endpoints ({$percentage}%){$skippedTag}\n";
            $output .= str_repeat('-', 50) . "\n";

            $output .= self::renderCovered($result, $consoleOutput);
            $output .= self::renderUncovered($result, $consoleOutput);
        }

        $output .= "\n";

        return $output;
    }

    /**
     * @param CoverageResult $result
     */
    private static function renderCovered(array $result, ConsoleOutput $consoleOutput): string
    {
        if ($result['covered'] === []) {
            return '';
        }

        if ($consoleOutput === ConsoleOutput::UNCOVERED_ONLY) {
            return "Covered: {$result['coveredCount']} endpoints\n";
        }

        $output = "Covered:\n";

        if ($result['skippedOnlyCount'] > 0) {
            $output .= self::SKIPPED_ONLY_LEGEND . "\n";
        }

        $skipSet = array_flip($result['skippedOnly']);

        foreach ($result['covered'] as $endpoint) {
            $marker = isset($skipSet[$endpoint]) ? '⚠' : '✓';
            $output .= "  {$marker} {$endpoint}\n";
        }

        return $output;
    }

    /**
     * @param CoverageResult $result
     */
    private static function renderUncovered(array $result, ConsoleOutput $consoleOutput): string
    {
        if ($result['uncovered'] === []) {
            return '';
        }

        $uncoveredCount = count($result['uncovered']);

        if ($consoleOutput === ConsoleOutput::DEFAULT) {
            return "Uncovered: {$uncoveredCount} endpoints\n";
        }

        $output = "Uncovered:\n";

        foreach ($result['uncovered'] as $endpoint) {
            $output .= "  ✗ {$endpoint}\n";
        }

        return $output;
    }

    private static function percentage(int $covered, int $total): float|int
    {
        return $total > 0 ? round($covered / $total * 100, 1) : 0;
    }
}
