<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use function array_keys;
use function count;
use function in_array;
use function sort;
use function strtoupper;

final class OpenApiCoverageTracker
{
    /** @var array<string, array<string, true>> key: "METHOD /path", grouped by spec name */
    private static array $covered = [];

    public static function record(string $specName, string $method, string $path): void
    {
        $key = strtoupper($method) . ' ' . $path;
        self::$covered[$specName][$key] = true;
    }

    /** @return array<string, array<string, true>> */
    public static function getCovered(): array
    {
        return self::$covered;
    }

    public static function reset(): void
    {
        self::$covered = [];
    }

    /**
     * @return array{covered: string[], uncovered: string[], total: int, coveredCount: int}
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

        foreach ($allEndpoints as $endpoint) {
            if (isset($coveredSet[$endpoint])) {
                $covered[] = $endpoint;
            } else {
                $uncovered[] = $endpoint;
            }
        }

        return [
            'covered' => $covered,
            'uncovered' => $uncovered,
            'total' => count($allEndpoints),
            'coveredCount' => count($covered),
        ];
    }
}
