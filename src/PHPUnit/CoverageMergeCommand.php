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
use function count;
use function explode;
use function file_put_contents;
use function getcwd;
use function getenv;
use function in_array;
use function is_callable;
use function is_numeric;
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
 * Designed to be invoked as a separate step after the parallel test run
 * finishes (e.g. via `bin/openapi-coverage-merge`), but the actual work
 * lives here as a class so it can be unit-tested without spawning a
 * subprocess.
 *
 * @phpstan-type MergeOptions array{
 *     sidecar_dir?: string,
 *     spec_base_path?: string,
 *     specs?: list<string>,
 *     strip_prefixes?: list<string>,
 *     output_file?: string,
 *     github_step_summary?: string,
 *     console_output?: string,
 *     cleanup?: bool,
 *     min_endpoint_coverage?: float,
 *     min_response_coverage?: float,
 *     min_coverage_strict?: bool,
 *     help?: bool,
 * }
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
     * @return MergeOptions
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
                case 'min_endpoint_coverage':
                case 'min_response_coverage':
                    // Skip silently when non-numeric — a casted "0.0" would
                    // mislead run() into believing a 0% gate was configured.
                    // Range / sign validation lives in run() so the user sees
                    // a single WARNING rather than two.
                    if (is_numeric($value)) {
                        $opts[$name] = (float) $value;
                    }

                    break;
                case 'min_coverage_strict':
                    $opts['min_coverage_strict'] = !in_array($value, ['0', 'false', 'no'], true);

                    break;
                default:
                    $opts[$name] = $value;
            }
        }

        /** @var MergeOptions $opts */
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
              --min-endpoint-coverage=<pct> Fail-fast (with --min-coverage-strict) when fully-
                                            covered-endpoint percent is below this value (0-100).
              --min-response-coverage=<pct> Same idea, against (status, content-type) granularity.
              --min-coverage-strict[=BOOL]  Treat threshold misses as exit non-zero (default
                                            warn-only).
              --no-cleanup                  Keep sidecar files after merge (default: cleanup).
              --help                        Show this message.

            USAGE;
    }

    /**
     * @param MergeOptions $options
     *
     * @return int 0 on success, non-zero on misconfiguration / I/O failure
     */
    public function run(array $options): int
    {
        if (($options['help'] ?? false) === true) {
            $this->writeStdout(self::usage());

            return 0;
        }

        $sidecarDir = isset($options['sidecar_dir']) && $options['sidecar_dir'] !== ''
            ? $this->absolutise($options['sidecar_dir'])
            : OpenApiCoverageExtension::defaultSidecarDir();

        // Empty `--specs=` is treated as "use default" rather than "use no
        // specs". Otherwise a misconfigured CLI invocation would silently
        // exit with "no coverage recorded" instead of warning the user.
        $specs = isset($options['specs']) && $options['specs'] !== [] ? $options['specs'] : ['front'];

        $specBasePath = isset($options['spec_base_path']) && $options['spec_base_path'] !== ''
            ? $this->absolutise($options['spec_base_path'])
            : null;
        $stripPrefixes = $options['strip_prefixes'] ?? [];
        $outputFile = isset($options['output_file']) && $options['output_file'] !== ''
            ? $this->absolutise($options['output_file'])
            : null;
        $githubSummaryPath = isset($options['github_step_summary']) && $options['github_step_summary'] !== ''
            ? $options['github_step_summary']
            : (getenv('GITHUB_STEP_SUMMARY') ?: null);
        $consoleOutput = ConsoleOutput::resolve($options['console_output'] ?? null);
        $cleanup = $options['cleanup'] ?? true;
        $minEndpointPct = $this->resolveThreshold('min_endpoint_coverage', $options['min_endpoint_coverage'] ?? null);
        $minResponsePct = $this->resolveThreshold('min_response_coverage', $options['min_response_coverage'] ?? null);
        $minStrict = $options['min_coverage_strict'] ?? false;

        if ($specBasePath === null) {
            $this->writeStderr("[OpenAPI Coverage] FATAL: --spec-base-path is required\n");

            return 2;
        }

        // Detect worker failure markers BEFORE attempting the merge: even
        // one missing worker means the report under-counts coverage, which
        // is exactly the silent failure parallel-mode introduced. Fail
        // loudly so CI gating sees a non-zero exit.
        $failureMarkers = CoverageSidecarReader::listFailureMarkerPaths($sidecarDir);
        if ($failureMarkers !== []) {
            $this->writeStderr(sprintf(
                "[OpenAPI Coverage] FATAL: %d worker(s) failed to write a sidecar; merge would under-count coverage. Markers in %s\n",
                count($failureMarkers),
                $sidecarDir,
            ));

            return 1;
        }

        try {
            $payloads = CoverageSidecarReader::readDir($sidecarDir);
        } catch (RuntimeException $e) {
            $this->writeStderr(sprintf("[OpenAPI Coverage] FATAL: %s\n", $e->getMessage()));

            return 1;
        }

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

        $writeFailures = 0;
        if ($outputFile !== null || $githubSummaryPath !== null) {
            $markdown = MarkdownCoverageRenderer::render($results);

            // Suppress PHP warning on failure — we surface the error
            // ourselves via stderr + exit code so the warning is redundant
            // noise that breaks `beStrictAboutOutputDuringTests` test runs.
            if ($outputFile !== null && @file_put_contents($outputFile, $markdown) === false) {
                $this->writeStderr(sprintf("[OpenAPI Coverage] FATAL: Failed to write Markdown report to %s\n", $outputFile));
                $writeFailures++;
            }
            if ($githubSummaryPath !== null && @file_put_contents($githubSummaryPath, $markdown . "\n", FILE_APPEND) === false) {
                $this->writeStderr(sprintf("[OpenAPI Coverage] WARNING: Failed to append Markdown report to GITHUB_STEP_SUMMARY (%s)\n", $githubSummaryPath));
            }
        }

        $thresholdFailure = $this->evaluateThresholdGate($results, $minEndpointPct, $minResponsePct, $minStrict);

        if ($cleanup) {
            $this->cleanup($sidecarDir);
        }

        return $writeFailures > 0 || $thresholdFailure ? 1 : 0;
    }

    /**
     * Run the threshold gate against rolled-up results. Prints the evaluator's
     * pre-formatted message to stderr when at least one threshold misses; the
     * caller decides what to do with the return value (only `strict=true`
     * misses propagate to a non-zero exit).
     *
     * @param array<string, array<string, mixed>> $results
     */
    private function evaluateThresholdGate(
        array $results,
        ?float $minEndpointPct,
        ?float $minResponsePct,
        bool $strict,
    ): bool {
        if ($minEndpointPct === null && $minResponsePct === null) {
            return false;
        }

        /** @var array<string, array{endpoints: list<mixed>, endpointTotal: int, endpointFullyCovered: int, endpointPartial: int, endpointUncovered: int, endpointRequestOnly: int, responseTotal: int, responseCovered: int, responseSkipped: int, responseUncovered: int}> $results */
        $evaluation = CoverageThresholdEvaluator::evaluate($results, $minEndpointPct, $minResponsePct, $strict);

        if ($evaluation['passed']) {
            return false;
        }

        $this->writeStderr($evaluation['message']);

        return $strict;
    }

    /**
     * Validate a percentage threshold from CLI options. Out-of-range values
     * become a `WARNING` and are dropped so a misconfiguration surfaces in
     * the run log instead of silently inverting the gate.
     */
    private function resolveThreshold(string $name, mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (!is_numeric($value)) {
            $this->writeStderr(sprintf(
                "[OpenAPI Coverage] WARNING: %s='%s' is not a number; skipping threshold gate.\n",
                $name,
                (string) $value,
            ));

            return null;
        }

        $float = (float) $value;
        if ($float < 0.0 || $float > 100.0) {
            $this->writeStderr(sprintf(
                "[OpenAPI Coverage] WARNING: %s=%s is out of range (expected 0-100); skipping threshold gate.\n",
                $name,
                (string) $float,
            ));

            return null;
        }

        return $float;
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
        $paths = [
            ...CoverageSidecarReader::listPaths($sidecarDir),
            ...CoverageSidecarReader::listFailureMarkerPaths($sidecarDir),
        ];
        foreach ($paths as $path) {
            // Surface unlink failures: a leftover sidecar is silently merged
            // into the next run and would over-count coverage.
            if (!@unlink($path)) {
                $this->writeStderr(sprintf(
                    "[OpenAPI Coverage] WARNING: Failed to delete sidecar/marker after merge: %s\n",
                    $path,
                ));
            }
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
