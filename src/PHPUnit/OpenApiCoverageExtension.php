<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\PHPUnit;

use const FILE_APPEND;
use const PHP_EOL;
use const STDERR;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Studio\OpenApiContractTesting\InvalidOpenApiSpecException;
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
     * @internal exposed so the extension's subscriber can reuse the stream override.
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
     * `$facade` is nullable so unit tests can exercise the eager-load path
     * without supplying a real `Facade`. Its shape changed between PHPUnit
     * 11/12 (class) and 13 (interface), so a portable stub is not possible;
     * skipping subscriber registration in tests is the clean fix.
     *
     * @internal
     */
    public function setupExtension(?Facade $facade, ParameterCollection $parameters, ?string $githubSummaryPath): void
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

        if ($facade === null) {
            return;
        }

        $facade->registerSubscriber(new CoverageReportSubscriber(
            specs: $specs,
            outputFile: $outputFile,
            consoleOutput: $consoleOutput,
            githubSummaryPath: $githubSummaryPath,
        ));
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
