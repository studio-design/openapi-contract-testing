<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use function array_keys;
use function count;
use function in_array;
use function sort;
use function strtoupper;

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
final class OpenApiCoverageTracker
{
    /**
     * `validated` is monotonic — once true, a later skipped record does not
     * demote it — so ordering of observations across a suite does not matter.
     *
     * @var array<string, array<string, array{validated: bool, skipped: bool}>>
     */
    private static array $covered = [];

    public static function record(
        string $specName,
        string $method,
        string $path,
        bool $schemaValidated = true,
    ): void {
        $key = strtoupper($method) . ' ' . $path;
        $entry = self::$covered[$specName][$key] ?? ['validated' => false, 'skipped' => false];

        if ($schemaValidated) {
            $entry['validated'] = true;
        } else {
            $entry['skipped'] = true;
        }

        self::$covered[$specName][$key] = $entry;
    }

    /**
     * Flattens the internal 2-flag entry to `true` to preserve the public
     * shape. Richer skipped-only data lives on computeCoverage().
     *
     * @return array<string, array<string, true>>
     */
    public static function getCovered(): array
    {
        $external = [];

        foreach (self::$covered as $spec => $endpoints) {
            foreach (array_keys($endpoints) as $endpoint) {
                $external[$spec][$endpoint] = true;
            }
        }

        return $external;
    }

    public static function reset(): void
    {
        self::$covered = [];
    }

    /**
     * @return CoverageResult
     */
    public static function computeCoverage(string $specName): array
    {
        $spec = OpenApiSpecLoader::load($specName);
        $allEndpoints = [];

        /** @var array<string, mixed> $methods */
        foreach ($spec['paths'] ?? [] as $path => $methods) {
            foreach (array_keys($methods) as $method) {
                $upper = strtoupper((string) $method);
                if (in_array($upper, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    $allEndpoints[] = "{$upper} {$path}";
                }
            }
        }

        sort($allEndpoints);

        $coveredSet = self::$covered[$specName] ?? [];
        $covered = [];
        $uncovered = [];
        $skippedOnly = [];

        foreach ($allEndpoints as $endpoint) {
            if (isset($coveredSet[$endpoint])) {
                $covered[] = $endpoint;
                $entry = $coveredSet[$endpoint];
                if ($entry['skipped'] && !$entry['validated']) {
                    $skippedOnly[] = $endpoint;
                }
            } else {
                $uncovered[] = $endpoint;
            }
        }

        return [
            'covered' => $covered,
            'uncovered' => $uncovered,
            'total' => count($allEndpoints),
            'coveredCount' => count($covered),
            'skippedOnly' => $skippedOnly,
            'skippedOnlyCount' => count($skippedOnly),
        ];
    }
}
