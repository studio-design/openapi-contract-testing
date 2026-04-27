<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\PHPUnit;

use const FILE_APPEND;

use InvalidArgumentException;
use RuntimeException;
use Studio\OpenApiContractTesting\InvalidOpenApiSpecException;
use Studio\OpenApiContractTesting\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\SpecFileNotFoundException;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function file_put_contents;
use function getcwd;
use function getenv;
use function in_array;
use function is_array;
use function is_bool;
use function is_callable;
use function is_string;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function substr;
use function unlink;

/**
 * Aggregates worker sidecars (produced by {@see CoverageReportSubscriber} in
 * paratest mode) into a single coverage report — the parallel-runner
 * counterpart to the in-process subscriber rendering.
 *
 * Designed to be invoked from `bin/openapi-coverage-merge` after `pest
 * --parallel` / `paratest` finishes, but the actual work lives here as a
 * class so it can be unit-tested without spawning a subprocess.
 */
final class CoverageMergeCommand
{
    /**
     * @param null|callable(string): void $stderrWriter Optional sink for warnings; defaults to STDERR.
     */
    public function __construct(
        private mixed $stderrWriter = null,
        private mixed $stdoutWriter = null,
    ) {}

    /**
     * Parse argv into the option array consumed by {@see self::run()}. Kept
     * separate so unit tests can drive `run()` directly with a structured
     * payload while the CLI binary parses real `--flag=value` arguments.
     *
     * @param list<string> $argv excluding the script name
     *
     * @return array{
     *     sidecar_dir?: string,
     *     spec_base_path?: string,
     *     specs?: list<string>,
     *     strip_prefixes?: list<string>,
     *     output_file?: string,
     *     github_step_summary?: string,
     *     console_output?: string,
     *     cleanup?: bool,
     *     help?: bool,
     * }
     */
    public static function parseArgv(array $argv): array
    {
        $opts = [];
        foreach ($argv as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                $opts['help'] = true;

                continue;
            }
            if (!str_starts_with($arg, '--')) {
                continue;
            }
            $rest = substr($arg, 2);
            if (str_contains($rest, '=')) {
                [$name, $value] = explode('=', $rest, 2);
            } else {
                $name = $rest;
                $value = 'true';
            }
            $name = str_replace('-', '_', $name);

            switch ($name) {
                case 'specs':
                case 'strip_prefixes':
                    $opts[$name] = array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $v): bool => $v !== ''));

                    break;
                case 'cleanup':
                    $opts['cleanup'] = !in_array($value, ['0', 'false', 'no'], true);

                    break;
                case 'no_cleanup':
                    $opts['cleanup'] = false;

                    break;
                default:
                    $opts[$name] = $value;
            }
        }

        /** @var array{
         *     sidecar_dir?: string,
         *     spec_base_path?: string,
         *     specs?: list<string>,
         *     strip_prefixes?: list<string>,
         *     output_file?: string,
         *     github_step_summary?: string,
         *     console_output?: string,
         *     cleanup?: bool,
         *     help?: bool,
         * } $opts
         */
        return $opts;
    }

    public static function usage(): string
    {
        return <<<USAGE
            openapi-coverage-merge — combine paratest worker sidecars into one coverage report.

            Usage:
              openapi-coverage-merge --spec-base-path=<path> [options]

            Options:
              --spec-base-path=<path>       Path to bundled spec directory (required).
              --specs=<a,b>                 Comma-separated spec names. Defaults to "front".
              --strip-prefixes=<a,b>        Comma-separated request-path prefixes to strip.
              --sidecar-dir=<path>          Worker sidecar directory. Defaults to
                                            sys_get_temp_dir()/openapi-coverage-sidecars.
              --output-file=<path>          Markdown report output path.
              --github-step-summary=<path>  Append Markdown report to this file (also
                                            consults GITHUB_STEP_SUMMARY env var).
              --console-output=<mode>       default | all | uncovered_only.
              --no-cleanup                  Keep sidecar files after merge (default: cleanup).
              --help                        Show this message.

            USAGE;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return int 0 on success, non-zero on misconfiguration / I/O failure
     */
    public function run(array $options): int
    {
        if (($options['help'] ?? false) === true) {
            $this->writeStdout(self::usage());

            return 0;
        }

        $sidecarDir = isset($options['sidecar_dir']) && is_string($options['sidecar_dir']) && $options['sidecar_dir'] !== ''
            ? $this->absolutise($options['sidecar_dir'])
            : OpenApiCoverageExtension::defaultSidecarDir();

        $specs = isset($options['specs']) && is_array($options['specs']) ? array_values($options['specs']) : ['front'];
        $specBasePath = isset($options['spec_base_path']) && is_string($options['spec_base_path']) && $options['spec_base_path'] !== ''
            ? $this->absolutise($options['spec_base_path'])
            : null;
        $stripPrefixes = isset($options['strip_prefixes']) && is_array($options['strip_prefixes']) ? array_values($options['strip_prefixes']) : [];
        $outputFile = isset($options['output_file']) && is_string($options['output_file']) && $options['output_file'] !== ''
            ? $this->absolutise($options['output_file'])
            : null;
        $githubSummaryPath = isset($options['github_step_summary']) && is_string($options['github_step_summary']) && $options['github_step_summary'] !== ''
            ? $options['github_step_summary']
            : (getenv('GITHUB_STEP_SUMMARY') ?: null);
        $consoleOutput = ConsoleOutput::resolve(isset($options['console_output']) && is_string($options['console_output']) ? $options['console_output'] : null);
        $cleanup = isset($options['cleanup']) && is_bool($options['cleanup']) ? $options['cleanup'] : true;

        if ($specBasePath === null) {
            $this->writeStderr("[OpenAPI Coverage] FATAL: --spec-base-path is required\n");

            return 2;
        }

        $payloads = $this->loadPayloads($sidecarDir);
        if ($payloads === []) {
            $this->writeStderr(sprintf("[OpenAPI Coverage] WARNING: no sidecars found in %s\n", $sidecarDir));

            return 0;
        }

        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure($specBasePath, $stripPrefixes);

        OpenApiCoverageTracker::reset();
        foreach ($payloads as $payload) {
            try {
                OpenApiCoverageTracker::importState($payload);
            } catch (InvalidArgumentException $e) {
                $this->writeStderr(sprintf("[OpenAPI Coverage] FATAL: invalid sidecar payload: %s\n", $e->getMessage()));

                return 1;
            }
        }

        $results = $this->computeResults($specs);
        if ($results === []) {
            $this->writeStderr("[OpenAPI Coverage] WARNING: no coverage recorded across sidecars\n");

            if ($cleanup) {
                $this->cleanup($sidecarDir);
            }

            return 0;
        }

        $this->writeStdout(ConsoleCoverageRenderer::render($results, $consoleOutput));

        if ($outputFile !== null || $githubSummaryPath !== null) {
            $markdown = MarkdownCoverageRenderer::render($results);

            if ($outputFile !== null && file_put_contents($outputFile, $markdown) === false) {
                $this->writeStderr(sprintf("[OpenAPI Coverage] WARNING: Failed to write Markdown report to %s\n", $outputFile));
            }
            if ($githubSummaryPath !== null && file_put_contents($githubSummaryPath, $markdown . "\n", FILE_APPEND) === false) {
                $this->writeStderr(sprintf("[OpenAPI Coverage] WARNING: Failed to append Markdown report to GITHUB_STEP_SUMMARY (%s)\n", $githubSummaryPath));
            }
        }

        if ($cleanup) {
            $this->cleanup($sidecarDir);
        }

        return 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadPayloads(string $sidecarDir): array
    {
        try {
            return CoverageSidecarReader::readDir($sidecarDir);
        } catch (RuntimeException $e) {
            $this->writeStderr(sprintf("[OpenAPI Coverage] FATAL: %s\n", $e->getMessage()));

            return [];
        }
    }

    /**
     * @param list<string> $specs
     *
     * @return array<string, array<string, mixed>>
     */
    private function computeResults(array $specs): array
    {
        $hasCoverage = false;
        foreach ($specs as $spec) {
            if (OpenApiCoverageTracker::hasAnyCoverage($spec)) {
                $hasCoverage = true;

                break;
            }
        }
        if (!$hasCoverage) {
            return [];
        }

        $results = [];
        foreach ($specs as $spec) {
            try {
                $results[$spec] = OpenApiCoverageTracker::computeCoverage($spec);
            } catch (SpecFileNotFoundException $e) {
                $this->writeStderr(sprintf("[OpenAPI Coverage] WARNING: Skipping spec '%s': %s\n", $spec, $e->getMessage()));
            } catch (InvalidOpenApiSpecException $e) {
                $this->writeStderr(sprintf("[OpenAPI Coverage] FATAL: Invalid OpenAPI spec '%s': %s\n", $spec, $e->getMessage()));

                throw $e;
            }
        }

        return $results;
    }

    private function cleanup(string $sidecarDir): void
    {
        foreach (CoverageSidecarReader::listPaths($sidecarDir) as $path) {
            @unlink($path);
        }
    }

    private function absolutise(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return (getcwd() ?: '.') . '/' . $path;
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

    private function writeStdout(string $message): void
    {
        $writer = $this->stdoutWriter;
        if (is_callable($writer)) {
            $writer($message);

            return;
        }

        echo $message;
    }
}
