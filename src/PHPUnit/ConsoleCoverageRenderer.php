<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\PHPUnit;

use const STR_PAD_RIGHT;

use Studio\OpenApiContractTesting\OpenApiCoverageTracker;

use function round;
use function sprintf;
use function str_pad;
use function str_repeat;

/**
 * @phpstan-import-type CoverageResult from OpenApiCoverageTracker
 * @phpstan-import-type EndpointSummary from OpenApiCoverageTracker
 * @phpstan-import-type ResponseRow from OpenApiCoverageTracker
 */
final class ConsoleCoverageRenderer
{
    private const MARKER_ALL_COVERED = '✓';
    private const MARKER_PARTIAL = '◐';
    private const MARKER_SKIPPED = '⚠';
    private const MARKER_UNCOVERED = '✗';
    private const MARKER_REQUEST_ONLY = '·';

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
            $endpointPct = self::percentage($result['endpointFullyCovered'], $result['endpointTotal']);
            $responsePct = self::percentage($result['responseCovered'], $result['responseTotal']);

            $output .= sprintf(
                "\n[%s] endpoints: %d/%d fully covered (%s%%), %d partial, %d uncovered\n",
                $spec,
                $result['endpointFullyCovered'],
                $result['endpointTotal'],
                $endpointPct,
                $result['endpointPartial'],
                $result['endpointUncovered'],
            );
            $output .= sprintf(
                "        responses: %d/%d covered (%s%%), %d skipped, %d uncovered\n",
                $result['responseCovered'],
                $result['responseTotal'],
                $responsePct,
                $result['responseSkipped'],
                $result['responseUncovered'],
            );
            $output .= str_repeat('-', 50) . "\n";
            $output .= "Legend: ✓=validated  ⚠=skipped  ✗=uncovered  ◐=partial  ·=request-only  *=any/no content-type\n";

            $output .= self::renderEndpoints($result['endpoints'], $consoleOutput);
        }

        $output .= "\n";

        return $output;
    }

    /**
     * @param list<EndpointSummary> $endpoints
     */
    private static function renderEndpoints(array $endpoints, ConsoleOutput $mode): string
    {
        if ($endpoints === []) {
            return '';
        }

        $output = '';
        foreach ($endpoints as $endpoint) {
            // DEFAULT mode renders one line per endpoint with no sub-rows.
            // ALL renders sub-rows for every endpoint. UNCOVERED_ONLY only
            // shows sub-rows when the endpoint isn't all-covered, so a
            // green run stays compact.
            $showSubRows = match ($mode) {
                ConsoleOutput::DEFAULT => false,
                ConsoleOutput::ALL => true,
                ConsoleOutput::UNCOVERED_ONLY => $endpoint['state'] !== 'all-covered',
            };

            $output .= sprintf(
                "  %s %s%s\n",
                self::endpointMarker($endpoint['state']),
                $endpoint['endpoint'],
                self::endpointSummaryTail($endpoint),
            );

            if (!$showSubRows) {
                continue;
            }

            foreach ($endpoint['responses'] as $row) {
                if ($mode === ConsoleOutput::UNCOVERED_ONLY && $row['state'] === 'validated') {
                    continue;
                }
                $output .= sprintf(
                    "      %s %s  %s%s\n",
                    self::responseMarker($row['state']),
                    str_pad($row['statusKey'], 5, ' ', STR_PAD_RIGHT),
                    str_pad($row['contentTypeKey'], 32, ' ', STR_PAD_RIGHT),
                    self::responseTail($row),
                );
            }

            foreach ($endpoint['unexpectedObservations'] as $obs) {
                $output .= sprintf(
                    "      ! %s  %s  unexpected (not in spec)\n",
                    str_pad($obs['statusKey'], 5, ' ', STR_PAD_RIGHT),
                    str_pad($obs['contentTypeKey'], 32, ' ', STR_PAD_RIGHT),
                );
            }
        }

        return $output;
    }

    /**
     * @param EndpointSummary $endpoint
     */
    private static function endpointSummaryTail(array $endpoint): string
    {
        if ($endpoint['totalResponseCount'] === 0) {
            return $endpoint['requestReached'] ? '  (request only)' : '';
        }

        $tail = sprintf('  (%d/%d responses', $endpoint['coveredResponseCount'], $endpoint['totalResponseCount']);
        if ($endpoint['skippedResponseCount'] > 0) {
            $tail .= sprintf(', %d skipped', $endpoint['skippedResponseCount']);
        }

        return $tail . ')';
    }

    /**
     * @param ResponseRow $row
     */
    private static function responseTail(array $row): string
    {
        return match ($row['state']) {
            'validated' => sprintf('[%d]', $row['hits']),
            'skipped' => $row['skipReason'] !== null
                ? sprintf('skipped: %s', $row['skipReason'])
                : 'skipped',
            default => 'uncovered',
        };
    }

    private static function endpointMarker(string $state): string
    {
        return match ($state) {
            'all-covered' => self::MARKER_ALL_COVERED,
            'partial' => self::MARKER_PARTIAL,
            'request-only' => self::MARKER_REQUEST_ONLY,
            default => self::MARKER_UNCOVERED,
        };
    }

    private static function responseMarker(string $state): string
    {
        return match ($state) {
            'validated' => self::MARKER_ALL_COVERED,
            'skipped' => self::MARKER_SKIPPED,
            default => self::MARKER_UNCOVERED,
        };
    }

    private static function percentage(int $covered, int $total): float|int
    {
        return $total > 0 ? round($covered / $total * 100, 1) : 0;
    }
}
