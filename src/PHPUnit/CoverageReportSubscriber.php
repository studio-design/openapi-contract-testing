<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\PHPUnit;

use const FILE_APPEND;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use Studio\OpenApiContractTesting\InvalidOpenApiSpecException;
use Studio\OpenApiContractTesting\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\SpecFileNotFoundException;

use function file_put_contents;

/**
 * @phpstan-import-type CoverageResult from OpenApiCoverageTracker
 */
final readonly class CoverageReportSubscriber implements ExecutionFinishedSubscriber
{
    /**
     * @param string[] $specs
     */
    public function __construct(
        private array $specs,
        private ?string $outputFile,
        private ConsoleOutput $consoleOutput,
        private ?string $githubSummaryPath,
    ) {}

    /** @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter */
    public function notify(ExecutionFinished $event): void
    {
        $results = $this->computeAllResults();

        // Free cached spec data now that coverage has been computed
        OpenApiSpecLoader::clearCache();

        if ($results === []) {
            return;
        }

        echo ConsoleCoverageRenderer::render($results, $this->consoleOutput);

        if ($this->outputFile !== null || $this->githubSummaryPath !== null) {
            $this->writeMarkdownReport($results);
        }
    }

    /**
     * @return array<string, CoverageResult>
     */
    private function computeAllResults(): array
    {
        $hasCoverage = false;
        foreach ($this->specs as $spec) {
            if (OpenApiCoverageTracker::hasAnyCoverage($spec)) {
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
            } catch (SpecFileNotFoundException $e) {
                // Warn-and-continue for stale specs=, same semantics
                // as bootstrap. Unlike bootstrap, the subscriber runs
                // after tests finished, so continuing lets partial
                // coverage reports still render.
                OpenApiCoverageExtension::writeStderr("[OpenAPI Coverage] WARNING: Skipping spec '{$spec}': {$e->getMessage()}\n");

                continue;
            } catch (InvalidOpenApiSpecException $e) {
                // Defensive: only reachable if OpenApiSpecLoader::evict()
                // was called mid-run and the on-disk spec was edited
                // between bootstrap and ExecutionFinished. Preserves
                // the hard-fail contract in that edge case.
                OpenApiCoverageExtension::writeStderr("[OpenAPI Coverage] FATAL: Invalid OpenAPI spec '{$spec}': {$e->getMessage()}\n");

                throw $e;
            }
        }

        return $results;
    }

    /**
     * @param array<string, CoverageResult> $results
     */
    private function writeMarkdownReport(array $results): void
    {
        $markdown = MarkdownCoverageRenderer::render($results);

        if ($this->outputFile !== null) {
            $written = file_put_contents($this->outputFile, $markdown);

            if ($written === false) {
                OpenApiCoverageExtension::writeStderr("[OpenAPI Coverage] WARNING: Failed to write Markdown report to {$this->outputFile}\n");
            }
        }

        if ($this->githubSummaryPath !== null) {
            $written = file_put_contents($this->githubSummaryPath, $markdown . "\n", FILE_APPEND);

            if ($written === false) {
                OpenApiCoverageExtension::writeStderr("[OpenAPI Coverage] WARNING: Failed to append Markdown report to GITHUB_STEP_SUMMARY ({$this->githubSummaryPath})\n");
            }
        }
    }
}
