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
use RuntimeException;
use Studio\OpenApiContractTesting\InvalidOpenApiSpecException;
use Studio\OpenApiContractTesting\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\OpenApiSpecLoader;

use function array_map;
use function explode;
use function file_put_contents;
use function fwrite;
use function getcwd;
use function getenv;
use function str_starts_with;

final class OpenApiCoverageExtension implements Extension
{
    /**
     * Test-only override for STDERR writes. null means "use STDERR".
     *
     * @var null|resource
     */
    private static $stderrOverride;

    /**
     * Redirect STDERR writes to a test-supplied stream. Pass null to restore.
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
            // PHPUnit's ExtensionBootstrapper::bootstrap() swallows Throwable
            // and converts it to testRunnerTriggeredPhpunitWarning, which does
            // not fail the run unless consumers also set failOnPhpunitWarning.
            // Relying on that flag would re-open the exact silent-pass hole
            // this extension exists to close, so force a non-zero exit here.
            exit(1);
        }
    }

    /**
     * Exposed for testing: accepts the injectable parts of bootstrap without
     * requiring a real PHPUnit Configuration (which is a final readonly class
     * with a 150-arg constructor and cannot reasonably be stubbed).
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

        // Eager-load every registered spec so $ref resolution failures surface
        // at PHPUnit bootstrap (hard fail) rather than being silently swallowed
        // when a test happens not to exercise the broken spec. File-not-found
        // and other non-ref RuntimeExceptions keep the legacy warn-and-continue
        // behavior so a stale entry in `specs=` doesn't block unrelated work.
        foreach ($specs as $spec) {
            try {
                OpenApiSpecLoader::load($spec);
            } catch (InvalidOpenApiSpecException $e) {
                self::writeStderr("[OpenAPI Coverage] FATAL: Invalid OpenAPI spec '{$spec}': {$e->getMessage()}\n");
                self::appendGithubStepSummaryFatalBlock($githubSummaryPath, $spec, $e->getMessage());

                throw $e;
            } catch (RuntimeException $e) {
                self::writeStderr("[OpenAPI Coverage] WARNING: Skipping spec '{$spec}': {$e->getMessage()}\n");
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
                    } catch (InvalidOpenApiSpecException $e) {
                        // Defensive: bootstrap eager-load should have already
                        // aborted the run. Surface the error prominently if a
                        // later cache eviction or spec edit somehow revived it.
                        OpenApiCoverageExtension::writeStderr("[OpenAPI Coverage] FATAL: Invalid OpenAPI spec '{$spec}': {$e->getMessage()}\n");

                        throw $e;
                    } catch (RuntimeException $e) {
                        OpenApiCoverageExtension::writeStderr("[OpenAPI Coverage] WARNING: Skipping spec '{$spec}': {$e->getMessage()}\n");

                        continue;
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
