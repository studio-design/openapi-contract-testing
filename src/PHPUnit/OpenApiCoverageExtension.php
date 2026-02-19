<?php

declare(strict_types=1);

namespace Wadakatu\OpenApiContractTesting\PHPUnit;

use const FILE_APPEND;
use const STDERR;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use RuntimeException;
use Wadakatu\OpenApiContractTesting\OpenApiCoverageTracker;
use Wadakatu\OpenApiContractTesting\OpenApiSpecLoader;

use function array_map;
use function explode;
use function file_put_contents;
use function fwrite;
use function getcwd;
use function getenv;
use function round;
use function str_repeat;
use function str_starts_with;

final class OpenApiCoverageExtension implements Extension
{
    /** @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter */
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if ($parameters->has('spec_base_path')) {
            $basePath = $parameters->get('spec_base_path');
            if (!str_starts_with($basePath, '/')) {
                $basePath = getcwd() . '/' . $basePath;
            }

            $stripPrefixes = [];
            if ($parameters->has('strip_prefixes')) {
                $stripPrefixes = array_map('trim', explode(',', $parameters->get('strip_prefixes')));
            }

            OpenApiSpecLoader::configure($basePath, $stripPrefixes);
        }

        $specs = ['front'];
        if ($parameters->has('specs')) {
            $specs = array_map('trim', explode(',', $parameters->get('specs')));
        }

        $outputFile = null;
        if ($parameters->has('output_file')) {
            $outputFile = $parameters->get('output_file');
            if (!str_starts_with($outputFile, '/')) {
                $outputFile = getcwd() . '/' . $outputFile;
            }
        }

        $githubSummaryPath = getenv('GITHUB_STEP_SUMMARY') ?: null;

        $facade->registerSubscriber(new class ($specs, $outputFile, $githubSummaryPath) implements ExecutionFinishedSubscriber {
            /**
             * @param string[] $specs
             */
            public function __construct(
                private readonly array $specs,
                private readonly ?string $outputFile,
                private readonly ?string $githubSummaryPath,
            ) {}

            /** @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter */
            public function notify(ExecutionFinished $event): void
            {
                $results = $this->computeAllResults();

                if ($results === []) {
                    return;
                }

                $this->printReport($results);

                if ($this->outputFile !== null || $this->githubSummaryPath !== null) {
                    $this->writeMarkdownReport($results);
                }
            }

            /**
             * @return array<string, array{covered: string[], uncovered: string[], total: int, coveredCount: int}>
             */
            private function computeAllResults(): array
            {
                $covered = OpenApiCoverageTracker::getCovered();
                $hasCoverage = false;

                foreach ($this->specs as $spec) {
                    if (isset($covered[$spec]) && $covered[$spec] !== []) {
                        $hasCoverage = true;

                        break;
                    }
                }

                if (!$hasCoverage) {
                    return [];
                }

                $results = [];

                foreach ($this->specs as $spec) {
                    try {
                        $results[$spec] = OpenApiCoverageTracker::computeCoverage($spec);
                    } catch (RuntimeException $e) {
                        fwrite(STDERR, "[OpenAPI Coverage] WARNING: Skipping spec '{$spec}': {$e->getMessage()}\n");

                        continue;
                    }
                }

                return $results;
            }

            /**
             * @param array<string, array{covered: string[], uncovered: string[], total: int, coveredCount: int}> $results
             */
            private function printReport(array $results): void
            {
                echo "\n\n";
                echo "OpenAPI Contract Test Coverage\n";
                echo str_repeat('=', 50) . "\n";

                foreach ($results as $spec => $result) {
                    $percentage = self::percentage($result['coveredCount'], $result['total']);

                    echo "\n[{$spec}] {$result['coveredCount']}/{$result['total']} endpoints ({$percentage}%)\n";
                    echo str_repeat('-', 50) . "\n";

                    if ($result['covered'] !== []) {
                        echo "Covered:\n";

                        foreach ($result['covered'] as $endpoint) {
                            echo "  ✓ {$endpoint}\n";
                        }
                    }

                    $uncoveredCount = $result['total'] - $result['coveredCount'];

                    if ($uncoveredCount > 0) {
                        echo "Uncovered: {$uncoveredCount} endpoints\n";
                    }
                }

                echo "\n";
            }

            /**
             * @param array<string, array{covered: string[], uncovered: string[], total: int, coveredCount: int}> $results
             */
            private function writeMarkdownReport(array $results): void
            {
                $markdown = MarkdownCoverageRenderer::render($results);

                if ($this->outputFile !== null) {
                    $written = file_put_contents($this->outputFile, $markdown);

                    if ($written === false) {
                        fwrite(STDERR, "[OpenAPI Coverage] WARNING: Failed to write Markdown report to {$this->outputFile}\n");
                    }
                }

                if ($this->githubSummaryPath !== null) {
                    $written = file_put_contents($this->githubSummaryPath, $markdown . "\n", FILE_APPEND);

                    if ($written === false) {
                        fwrite(STDERR, "[OpenAPI Coverage] WARNING: Failed to append Markdown report to GITHUB_STEP_SUMMARY ({$this->githubSummaryPath})\n");
                    }
                }
            }

            private static function percentage(int $covered, int $total): float|int
            {
                return $total > 0 ? round($covered / $total * 100, 1) : 0;
            }
        });
    }
}
