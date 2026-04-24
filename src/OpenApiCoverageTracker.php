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
    /**
     * Internal storage tracks two flags per endpoint so we can distinguish
     * endpoints that were only ever exercised under a skip (e.g. 5xx bodies
     * matched `skip_response_codes`) from ones whose body was actually
     * schema-validated at least once. "validated" is monotonic — once true,
     * a later skip does not demote it — matching how coverage semantics
     * accumulate across a test suite.
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
     * Back-compat external shape: flatten the internal 2-flag entry to
     * `true` so existing consumers (e.g. OpenApiCoverageExtension's
     * emptiness check) keep working without any change. The richer
     * skipped-only signal is exposed via computeCoverage() instead.
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
     * @return array{
     *     covered: string[],
     *     uncovered: string[],
     *     total: int,
     *     coveredCount: int,
     *     skippedOnly: string[],
     *     skippedOnlyCount: int,
     * }
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
