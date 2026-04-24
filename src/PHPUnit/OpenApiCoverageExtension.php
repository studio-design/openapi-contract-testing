<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\PHPUnit;

use const FILE_APPEND;
use const PHP_EOL;
use const STDERR;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Studio\OpenApiContractTesting\InvalidOpenApiSpecException;
use Studio\OpenApiContractTesting\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\SpecFileNotFoundException;

use function array_map;
use function explode;
use function fflush;
use function file_put_contents;
use function fwrite;
use function getcwd;
use function getenv;
use function str_starts_with;

final class OpenApiCoverageExtension implements Extension
{
    /**
     * Test-only override for STDERR writes.
     *
     * @var null|resource
     */
    private static $stderrOverride;

    /**
     * Redirect STDERR writes to a test-supplied stream.
     *
     * @param null|resource $stream
     *
     * @internal
     */
    public static function overrideStderrForTesting($stream): void
    {
        self::$stderrOverride = $stream;
    }

    /**
     * @internal exposed so the anonymous subscriber class can reuse the stream override.
     */
    public static function writeStderr(string $message): void
    {
        fwrite(self::$stderrOverride ?? STDERR, $message);
    }

    /** @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter */
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        try {
            $this->setupExtension($facade, $parameters, getenv('GITHUB_STEP_SUMMARY') ?: null);
        } catch (InvalidOpenApiSpecException) {
            // setupExtension() has already written a FATAL line to stderr and
            // (if GITHUB_STEP_SUMMARY is set) appended a fatal block to it.
            // PHPUnit's ExtensionBootstrapper::bootstrap() wraps this call in
            // catch(Throwable) and demotes it to testRunnerTriggeredPhpunitWarning,
            // which only fails the run when consumers opt in via
            // failOnPhpunitWarning (or failOnAllIssues). Depending on that
            // would re-open the silent-pass hole this extension exists to
            // close, so force a non-zero exit here. fflush guards against
            // output ordering surprises on exit().
            if (self::$stderrOverride === null) {
                fflush(STDERR);
            }

            exit(1);
        }
    }

    /**
     * Exposed for testing: accepts the injectable parts of bootstrap without
     * requiring a real PHPUnit `Configuration`, which is a `final readonly`
     * class with over 150 ctor parameters and is not reasonable to stub.
     *
     * @internal
     */
    public function setupExtension(Facade $facade, ParameterCollection $parameters, ?string $githubSummaryPath): void
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

        // Eager-load every registered spec so structural problems surface at
        // PHPUnit bootstrap (hard fail via bootstrap()) rather than being
        // silently swallowed when a test happens not to exercise the broken
        // spec. Only SpecFileNotFoundException keeps the legacy warn-and-
        // continue behavior, so a stale entry in `specs=` does not block
        // unrelated work; everything else — broken `$ref`, malformed
        // JSON/YAML, non-mapping root, missing `symfony/yaml` — is fatal.
        foreach ($specs as $spec) {
            try {
                OpenApiSpecLoader::load($spec);
            } catch (SpecFileNotFoundException $e) {
                self::writeStderr("[OpenAPI Coverage] WARNING: Skipping spec '{$spec}': {$e->getMessage()}\n");
            } catch (InvalidOpenApiSpecException $e) {
                self::writeStderr("[OpenAPI Coverage] FATAL: Invalid OpenAPI spec '{$spec}': {$e->getMessage()}\n");
                self::appendGithubStepSummaryFatalBlock($githubSummaryPath, $spec, $e->getMessage());

                throw $e;
            }
        }

        $outputFile = null;
        if ($parameters->has('output_file')) {
            $outputFile = $parameters->get('output_file');
            if (!str_starts_with($outputFile, '/')) {
                $outputFile = getcwd() . '/' . $outputFile;
            }
        }

        $consoleOutput = ConsoleOutput::resolve(
            $parameters->has('console_output') ? $parameters->get('console_output') : null,
        );

        $facade->registerSubscriber(new class ($specs, $outputFile, $consoleOutput, $githubSummaryPath) implements ExecutionFinishedSubscriber {
            /**
             * @param string[] $specs
             */
            public function __construct(
                private readonly array $specs,
                private readonly ?string $outputFile,
                private readonly ConsoleOutput $consoleOutput,
                private readonly ?string $githubSummaryPath,
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
             * @return array<string, array{
             *     covered: string[],
             *     uncovered: string[],
             *     total: int,
             *     coveredCount: int,
             *     skippedOnly: string[],
             *     skippedOnlyCount: int,
             * }>
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
             * @param array<string, array{
             *     covered: string[],
             *     uncovered: string[],
             *     total: int,
             *     coveredCount: int,
             *     skippedOnly: string[],
             *     skippedOnlyCount: int,
             * }> $results
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
        });
    }

    private static function appendGithubStepSummaryFatalBlock(?string $path, string $spec, string $reason): void
    {
        if ($path === null) {
            return;
        }

        $block = '## :rotating_light: FATAL OpenAPI spec error' . PHP_EOL
            . PHP_EOL
            . "Spec `{$spec}` could not be loaded and the test run was aborted." . PHP_EOL
            . PHP_EOL
            . '```' . PHP_EOL
            . $reason . PHP_EOL
            . '```' . PHP_EOL
            . PHP_EOL;

        $written = file_put_contents($path, $block, FILE_APPEND);
        if ($written === false) {
            self::writeStderr("[OpenAPI Coverage] WARNING: Failed to append FATAL block to GITHUB_STEP_SUMMARY ({$path})\n");
        }
    }
}
