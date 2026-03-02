<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\PHPUnit;

use function round;
use function str_repeat;

/**
 * @phpstan-type CoverageResult array{covered: string[], uncovered: string[], total: int, coveredCount: int}
 */
final class ConsoleCoverageRenderer
{
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
            $uncoveredCount = $result['total'] - $result['coveredCount'];

            $output .= "\n[{$spec}] {$result['coveredCount']}/{$result['total']} endpoints ({$percentage}%)\n";
            $output .= str_repeat('-', 50) . "\n";

            $output .= self::renderCovered($result, $consoleOutput, $uncoveredCount);
            $output .= self::renderUncovered($result, $consoleOutput, $uncoveredCount);
        }

        $output .= "\n";

        return $output;
    }

    /**
     * @param CoverageResult $result
     */
    private static function renderCovered(array $result, ConsoleOutput $consoleOutput, int $uncoveredCount): string
    {
        if ($result['covered'] === []) {
            return '';
        }

        if ($consoleOutput === ConsoleOutput::UNCOVERED_ONLY) {
            return "Covered: {$result['coveredCount']} endpoints\n";
        }

        $output = "Covered:\n";

        foreach ($result['covered'] as $endpoint) {
            $output .= "  ✓ {$endpoint}\n";
        }

        return $output;
    }

    /**
     * @param CoverageResult $result
     */
    private static function renderUncovered(array $result, ConsoleOutput $consoleOutput, int $uncoveredCount): string
    {
        if ($uncoveredCount <= 0) {
            return '';
        }

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
