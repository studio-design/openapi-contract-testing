<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\PHPUnit;

use const FILE_APPEND;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use RuntimeException;
use Studio\OpenApiContractTesting\InvalidOpenApiSpecException;
use Studio\OpenApiContractTesting\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\SpecFileNotFoundException;

use function file_put_contents;
use function getenv;
use function is_callable;
use function trim;

/**
 * @phpstan-import-type CoverageResult from OpenApiCoverageTracker
 */
final readonly class CoverageReportSubscriber implements ExecutionFinishedSubscriber
{
    /**
     * @param string[] $specs
     * @param null|callable(string): void $stderrWriter Optional sink for warnings (stale/invalid specs,
     *                                                  failed file_put_contents). Falls back to {@see OpenApiCoverageExtension::writeStderr()} when
     *                                                  null. Injected for testability — the extension stays the default backstop in production.
     * @param null|string $sidecarDir Directory the worker-mode branch will drop its JSON sidecar into. When the
     *                                subscriber detects `TEST_TOKEN` (set by paratest in every child process) it
     *                                short-circuits rendering and writes the tracker state here for the merge CLI
     *                                to pick up. `null` falls back to a default under `sys_get_temp_dir()`.
     */
    public function __construct(
        private array $specs,
        private ?string $outputFile,
        private ConsoleOutput $consoleOutput,
        private ?string $githubSummaryPath,
        private mixed $stderrWriter = null,
        private ?string $sidecarDir = null,
    ) {}

    /** @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter */
    public function notify(ExecutionFinished $event): void
    {
        $workerToken = self::resolveWorkerToken();
        if ($workerToken !== null) {
            $this->writeWorkerSidecar($workerToken);

            // Free cached spec data; the merge CLI re-loads on its own.
            OpenApiSpecLoader::clearCache();

            return;
        }

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
     * Resolve the paratest worker token from the environment. Paratest sets
     * `TEST_TOKEN` for every worker process (slot index 1..N) and unsets it
     * for sequential PHPUnit runs. We treat the presence of this var as the
     * single signal that puts the subscriber into sidecar-only mode.
     *
     * Pest v4 `--parallel` shells out to paratest, so the same env var is
     * present and no extra detection is needed.
     */
    private static function resolveWorkerToken(): ?string
    {
        $token = getenv('TEST_TOKEN');
        if ($token === false || trim($token) === '') {
            return null;
        }

        return $token;
    }

    private function writeWorkerSidecar(string $token): void
    {
        $dir = $this->sidecarDir ?? OpenApiCoverageExtension::defaultSidecarDir();

        try {
            CoverageSidecarWriter::write($dir, $token, OpenApiCoverageTracker::exportState());
        } catch (RuntimeException $e) {
            $this->writeStderr("[OpenAPI Coverage] WARNING: failed to write sidecar (token={$token}): {$e->getMessage()}\n");
        }
    }

    private function writeStderr(string $message): void
    {
        $writer = $this->stderrWriter;
        if (is_callable($writer)) {
            $writer($message);

            return;
        }

        OpenApiCoverageExtension::writeStderr($message);
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
                $this->writeStderr("[OpenAPI Coverage] WARNING: Skipping spec '{$spec}': {$e->getMessage()}\n");

                continue;
            } catch (InvalidOpenApiSpecException $e) {
                // Defensive: only reachable if OpenApiSpecLoader::evict()
                // was called mid-run and the on-disk spec was edited
                // between bootstrap and ExecutionFinished. Preserves
                // the hard-fail contract in that edge case.
                $this->writeStderr("[OpenAPI Coverage] FATAL: Invalid OpenAPI spec '{$spec}': {$e->getMessage()}\n");

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
                $this->writeStderr("[OpenAPI Coverage] WARNING: Failed to write Markdown report to {$this->outputFile}\n");
            }
        }

        if ($this->githubSummaryPath !== null) {
            $written = file_put_contents($this->githubSummaryPath, $markdown . "\n", FILE_APPEND);

            if ($written === false) {
                $this->writeStderr("[OpenAPI Coverage] WARNING: Failed to append Markdown report to GITHUB_STEP_SUMMARY ({$this->githubSummaryPath})\n");
            }
        }
    }
}
