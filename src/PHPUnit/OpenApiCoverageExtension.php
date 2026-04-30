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
use Studio\OpenApiContractTesting\InvalidThresholdConfigurationException;
use Studio\OpenApiContractTesting\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\SpecFileNotFoundException;

use function array_map;
use function explode;
use function fflush;
use function file_put_contents;
use function fwrite;
use function getcwd;
use function getenv;
use function in_array;
use function is_numeric;
use function sprintf;
use function str_starts_with;
use function sys_get_temp_dir;
use function trim;

final class OpenApiCoverageExtension implements Extension
{
    /**
     * Default location used by paratest workers when no `sidecar_dir`
     * parameter is configured. Kept stable across runs so the merge CLI
     * can find it without coordination, and namespaced enough that other
     * tools won't collide with it.
     */
    public const DEFAULT_SIDECAR_SUBDIR = 'openapi-coverage-sidecars';

    /**
     * Test-only override for STDERR writes.
     *
     * @var null|resource
     */
    private static $stderrOverride;

    public static function defaultSidecarDir(): string
    {
        return sys_get_temp_dir() . '/' . self::DEFAULT_SIDECAR_SUBDIR;
    }

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
        } catch (InvalidOpenApiSpecException|InvalidThresholdConfigurationException|SpecFileNotFoundException) {
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
        // spec. A spec named in `specs=` that doesn't resolve to a file is a
        // configuration error — not a stale leftover — so it is fatal too
        // (issue #134). Defensive warn-and-continue for missing files lives
        // downstream in CoverageReportSubscriber / CoverageMergeCommand,
        // where a mid-run unlink shouldn't lose the report.
        foreach ($specs as $spec) {
            try {
                OpenApiSpecLoader::load($spec);
            } catch (SpecFileNotFoundException $e) {
                self::writeStderr(
                    "[OpenAPI Coverage] FATAL: spec '{$spec}' configured in `specs=` is not loadable: {$e->getMessage()}\n"
                    . "  Action: regenerate the bundle (e.g. `cd openapi && npm run bundle`) or remove '{$spec}' from `specs=`.\n",
                );
                self::appendGithubStepSummaryFatalBlock($githubSummaryPath, $spec, $e->getMessage());

                throw $e;
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

        $sidecarDir = null;
        if ($parameters->has('sidecar_dir')) {
            $sidecarDir = $parameters->get('sidecar_dir');
            if (!str_starts_with($sidecarDir, '/')) {
                $sidecarDir = getcwd() . '/' . $sidecarDir;
            }
        }

        // Resolve strict first so threshold validation can promote bad values
        // to FATAL when the user opted in to fail-fast (issue #135 review C1).
        $minCoverageStrict = self::resolveStrictFlag($parameters);
        $minEndpointCoverage = self::resolveThresholdParameter($parameters, 'min_endpoint_coverage', $minCoverageStrict);
        $minResponseCoverage = self::resolveThresholdParameter($parameters, 'min_response_coverage', $minCoverageStrict);

        if ($facade === null) {
            return;
        }

        $facade->registerSubscriber(new CoverageReportSubscriber(
            specs: $specs,
            outputFile: $outputFile,
            consoleOutput: $consoleOutput,
            githubSummaryPath: $githubSummaryPath,
            sidecarDir: $sidecarDir,
            minEndpointCoverage: $minEndpointCoverage,
            minResponseCoverage: $minResponseCoverage,
            minCoverageStrict: $minCoverageStrict,
        ));
    }

    /**
     * Read a percentage parameter (`min_endpoint_coverage` /
     * `min_response_coverage`) from `phpunit.xml`. Mirrors the merge CLI's
     * resolveThreshold():
     *  - non-strict (warn-only): bad values become a `WARNING` and the gate
     *    is dropped so a misconfigured XML attribute surfaces in the log
     *    without breaking opt-in users.
     *  - strict:                 bad values become a `FATAL` and we throw
     *    {@see InvalidThresholdConfigurationException}, which `bootstrap()`
     *    catches and converts to `exit(1)`. A CI that opted into fail-fast
     *    must not silently lose its gate to a typo (issue #135 review C1).
     */
    private static function resolveThresholdParameter(
        ParameterCollection $parameters,
        string $name,
        bool $strict,
    ): ?float {
        if (!$parameters->has($name)) {
            return null;
        }
        $raw = trim($parameters->get($name));
        if ($raw === '') {
            return null;
        }
        if (!is_numeric($raw)) {
            self::reportInvalidThreshold($name, sprintf("%s='%s' is not a number", $name, $raw), $strict);

            return null;
        }
        $value = (float) $raw;
        if ($value < 0.0 || $value > 100.0) {
            self::reportInvalidThreshold(
                $name,
                sprintf('%s=%s is out of range (expected 0-100)', $name, (string) $value),
                $strict,
            );

            return null;
        }

        return $value;
    }

    /**
     * Emit a FATAL/WARNING line per `$strict`, then either drop the gate
     * (warn-only) or throw to short-circuit bootstrap with exit(1) (strict).
     * Suffix is identical for both branches so log greps match either way.
     */
    private static function reportInvalidThreshold(string $name, string $detail, bool $strict): void
    {
        $severity = $strict ? 'FATAL' : 'WARNING';
        $message = sprintf('%s; skipping threshold gate.', $detail);
        self::writeStderr(sprintf("[OpenAPI Coverage] %s: %s\n", $severity, $message));

        if ($strict) {
            throw new InvalidThresholdConfigurationException($name, $message);
        }
    }

    private static function resolveStrictFlag(ParameterCollection $parameters): bool
    {
        if (!$parameters->has('min_coverage_strict')) {
            return false;
        }
        $raw = trim($parameters->get('min_coverage_strict'));

        // Symmetric with the merge CLI's `--min-coverage-strict` (no-value
        // form): only explicit falsey strings disable strict mode. Empty
        // value (the `<parameter name="..." />` shorthand) is treated as
        // "set" so the XML and CLI sides agree.
        return !in_array($raw, ['0', 'false', 'no'], true);
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
