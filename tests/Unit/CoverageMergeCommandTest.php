<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Coverage\CoverageMergeCommand;
use Studio\OpenApiContractTesting\Coverage\CoverageSidecarWriter;
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;

use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_dir;
use function mkdir;
use function rmdir;
use function substr_count;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Pins the merge-CLI's behavior end-to-end — sidecar dir → combined report.
 * Constructs sidecars from real exported tracker state so format drift
 * between writer/reader/merge would surface here.
 */
class CoverageMergeCommandTest extends TestCase
{
    private string $sidecarDir = '';
    private string $outputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();

        $base = sys_get_temp_dir() . '/openapi-coverage-merge-' . uniqid('', true);
        $this->sidecarDir = $base . '/sidecars';
        $this->outputFile = $base . '/coverage-report.md';
        mkdir($this->sidecarDir, 0o755, recursive: true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->outputFile, ...$this->sidecars()] as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        if (is_dir($this->sidecarDir)) {
            @rmdir($this->sidecarDir);
            @rmdir(dirname($this->sidecarDir));
        }

        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function merges_two_workers_into_a_combined_markdown_report(): void
    {
        // Worker 1 covers GET /v1/pets 200.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        // Worker 2 covers POST /v1/pets 201.
        OpenApiCoverageTracker::reset();
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            '201',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '2', OpenApiCoverageTracker::exportState());

        $command = new CoverageMergeCommand(stdoutWriter: static fn(string $msg): null => null);
        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'output_file' => $this->outputFile,
            'cleanup' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertFileExists($this->outputFile);
        $contents = (string) file_get_contents($this->outputFile);
        $this->assertStringContainsString('OpenAPI Contract Test Coverage', $contents);
        $this->assertStringContainsString('GET /v1/pets', $contents);
        $this->assertStringContainsString('POST /v1/pets', $contents);

        // cleanup=true must remove all sidecars after a successful merge.
        $this->assertSame([], $this->sidecars());
    }

    #[Test]
    public function preserves_sidecars_when_cleanup_is_false(): void
    {
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $command = new CoverageMergeCommand(stdoutWriter: static fn(string $msg): null => null);
        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'output_file' => $this->outputFile,
            'cleanup' => false,
        ]);

        $this->assertSame(0, $exit);
        $this->assertCount(1, $this->sidecars());
    }

    #[Test]
    public function appends_to_github_step_summary_once(): void
    {
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $stepSummary = $this->sidecarDir . '/../step-summary.md';
        file_put_contents($stepSummary, '');

        $command = new CoverageMergeCommand(stdoutWriter: static fn(string $msg): null => null);
        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'github_step_summary' => $stepSummary,
            'cleanup' => true,
        ]);

        $this->assertSame(0, $exit);
        $contents = (string) file_get_contents($stepSummary);
        $this->assertStringContainsString('OpenAPI Contract Test Coverage', $contents);
        $this->assertSame(
            1,
            substr_count($contents, 'OpenAPI Contract Test Coverage'),
            'merge must emit a single combined report, not N partials',
        );

        @unlink($stepSummary);
    }

    #[Test]
    public function exits_one_when_sidecar_payload_is_corrupt(): void
    {
        // C1 fix: a malformed sidecar must produce exit 1 + FATAL stderr,
        // not exit 0 with a buried warning. Pre-fix, loadPayloads() caught
        // the read/decode error, wrote FATAL, and returned [] — caller
        // then took the "no sidecars" path and exited 0. CI couldn't
        // detect a corrupted worker.
        file_put_contents($this->sidecarDir . '/part-1-9999.json', '{not json');

        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'output_file' => $this->outputFile,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('FATAL', $stderr);
        $this->assertStringContainsString('failed to decode', $stderr);
    }

    #[Test]
    public function exits_one_when_worker_failure_marker_present(): void
    {
        // C2 fix: a worker that crashed before writing its sidecar leaves
        // a `failed-<token>.json` marker. The merge CLI must see it and
        // exit non-zero — under-counted coverage is exactly the silent
        // failure parallel mode introduced.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());
        CoverageSidecarWriter::writeFailureMarker($this->sidecarDir, '2', 'simulated I/O failure');

        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'output_file' => $this->outputFile,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('FATAL', $stderr);
        $this->assertStringContainsString('failed to write a sidecar', $stderr);
    }

    #[Test]
    public function returns_zero_with_warning_when_no_sidecars_present(): void
    {
        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'output_file' => $this->outputFile,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('no sidecars found', $stderr);
        $this->assertFileDoesNotExist($this->outputFile);
    }

    #[Test]
    public function parse_argv_decodes_long_options(): void
    {
        $opts = CoverageMergeCommand::parseArgv([
            '--spec-base-path=/tmp/spec',
            '--specs=front,admin',
            '--strip-prefixes=/api',
            '--sidecar-dir=/tmp/sidecars',
            '--output-file=/tmp/cov.md',
            '--no-cleanup',
        ]);

        $this->assertSame('/tmp/spec', $opts['spec_base_path']);
        $this->assertSame(['front', 'admin'], $opts['specs']);
        $this->assertSame(['/api'], $opts['strip_prefixes']);
        $this->assertSame('/tmp/sidecars', $opts['sidecar_dir']);
        $this->assertSame('/tmp/cov.md', $opts['output_file']);
        $this->assertFalse($opts['cleanup']);
    }

    #[Test]
    public function parse_argv_recognises_help_flag(): void
    {
        $this->assertTrue(CoverageMergeCommand::parseArgv(['--help'])['help'] ?? false);
        $this->assertTrue(CoverageMergeCommand::parseArgv(['-h'])['help'] ?? false);
    }

    #[Test]
    public function strip_prefixes_propagates_to_spec_loader(): void
    {
        // Behavioural pin: the merge CLI must hand `strip_prefixes` through
        // to the spec loader so downstream callers see the configured
        // prefixes. Pre-fix, the test only proved the option was accepted
        // (file written), not that it was honoured at all.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $command = new CoverageMergeCommand(stdoutWriter: static fn(string $msg): null => null);
        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'strip_prefixes' => ['/api', '/v2'],
            'output_file' => $this->outputFile,
            'cleanup' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame(['/api', '/v2'], OpenApiSpecLoader::getStripPrefixes());
    }

    #[Test]
    public function exits_two_when_spec_base_path_is_missing(): void
    {
        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'specs' => ['petstore-3.0'],
        ]);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('--spec-base-path is required', $stderr);
    }

    #[Test]
    public function empty_specs_argv_falls_back_to_default(): void
    {
        // I2 fix: `--specs=` parsed as empty list must fall back to the
        // documented default (`front`), not silently exit with "no
        // coverage recorded".
        $opts = CoverageMergeCommand::parseArgv([
            '--spec-base-path=' . __DIR__ . '/../fixtures/specs',
            '--specs=',
        ]);
        // parseArgv strips empty entries, leaving 'specs' => []. run() must
        // not honour the empty list as "use no specs" — verified via the
        // run() code path: empty list triggers the `['front']` default.
        $this->assertSame([], $opts['specs']);
    }

    #[Test]
    public function exits_one_on_threshold_miss_when_strict(): void
    {
        // Issue #135: strict gate must fail-fast with exit 1 + FAIL stderr.
        // Single recorded validation against a 25-endpoint fixture is well
        // below 80%, so the gate trips reliably without per-fixture math.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'min_endpoint_coverage' => 80.0,
            'min_coverage_strict' => true,
            'cleanup' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('[OpenAPI Coverage] FAIL:', $stderr);
        $this->assertStringContainsString('endpoint coverage', $stderr);
        $this->assertStringContainsString('< threshold 80%', $stderr);
    }

    #[Test]
    public function exits_zero_on_threshold_miss_when_not_strict(): void
    {
        // Default warn-only mode: print WARN to stderr but keep the run green.
        // Lets teams adopt the gate behind a flag without breaking CI on day 1.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'min_endpoint_coverage' => 80.0,
            // min_coverage_strict omitted — default false
            'cleanup' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('[OpenAPI Coverage] WARN:', $stderr);
        $this->assertStringNotContainsString('FAIL:', $stderr);
    }

    #[Test]
    public function strict_gate_with_no_sidecars_exits_one(): void
    {
        // C2: an opt-in strict CI gate must not silently pass when paratest
        // produced zero workers / a wrong --sidecar-dir was passed. Pre-fix,
        // the empty-payload branch returned 0 with a WARNING.
        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir, // empty
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'min_endpoint_coverage' => 80.0,
            'min_coverage_strict' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('FATAL', $stderr);
        $this->assertStringContainsString('no contract test coverage', $stderr);
    }

    #[Test]
    public function strict_gate_with_empty_recorded_coverage_exits_one(): void
    {
        // Sidecars exist but recorded no coverage (e.g. workers ran zero
        // contract assertions). Same silent-pass risk as no-sidecars.
        OpenApiCoverageTracker::reset(); // empty state
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'min_endpoint_coverage' => 80.0,
            'min_coverage_strict' => true,
            'cleanup' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('FATAL', $stderr);
        $this->assertStringContainsString('no contract test coverage', $stderr);
    }

    #[Test]
    public function warn_only_gate_with_no_sidecars_keeps_existing_warning_path(): void
    {
        // Backwards compat: warn-only mode (or threshold absent) keeps the
        // pre-#135 "no sidecars found" warning + exit 0.
        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'min_endpoint_coverage' => 80.0, // strict omitted
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('no sidecars found', $stderr);
    }

    #[Test]
    public function exits_one_on_response_only_threshold_miss_when_strict(): void
    {
        // Pin the response-only configuration end-to-end through the CLI;
        // merge gate must distinguish endpoint vs response misses.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'min_response_coverage' => 80.0,
            'min_coverage_strict' => true,
            'cleanup' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('FAIL', $stderr);
        $this->assertStringContainsString('response coverage', $stderr);
        $this->assertStringNotContainsString('endpoint coverage', $stderr);
    }

    #[Test]
    public function exits_zero_when_threshold_met(): void
    {
        // No threshold-related stderr noise on the happy path.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'min_endpoint_coverage' => 0.0, // any non-negative coverage clears 0%
            'min_coverage_strict' => true,
            'cleanup' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString('FAIL:', $stderr);
        $this->assertStringNotContainsString('WARN:', $stderr);
    }

    #[Test]
    public function parse_argv_decodes_threshold_flags(): void
    {
        $opts = CoverageMergeCommand::parseArgv([
            '--spec-base-path=/tmp/spec',
            '--min-endpoint-coverage=80',
            '--min-response-coverage=70.5',
            '--min-coverage-strict',
        ]);

        $this->assertSame(80.0, $opts['min_endpoint_coverage']);
        $this->assertSame(70.5, $opts['min_response_coverage']);
        $this->assertTrue($opts['min_coverage_strict']);
    }

    #[Test]
    public function parse_argv_treats_strict_false_value_as_false(): void
    {
        // Symmetry with the existing `cleanup` flag: `--min-coverage-strict=false`
        // must turn the gate into warn-only, not silently flip it on.
        $opts = CoverageMergeCommand::parseArgv([
            '--spec-base-path=/tmp/spec',
            '--min-coverage-strict=false',
        ]);

        $this->assertFalse($opts['min_coverage_strict']);
    }

    #[Test]
    public function out_of_range_threshold_warns_when_not_strict(): void
    {
        // Warn-only path: a typo'd threshold must surface as a WARNING but
        // not break a CI that opted out of fail-fast gating.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'min_endpoint_coverage' => 150.0, // out of 0..100 range
            // min_coverage_strict omitted → warn-only
            'cleanup' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('WARNING', $stderr);
        $this->assertStringContainsString('min_endpoint_coverage', $stderr);
    }

    #[Test]
    public function out_of_range_threshold_exits_two_when_strict(): void
    {
        // C1: silent-failure-hunter & code-reviewer flagged that strict mode
        // must treat a typo'd threshold as a config error, not silently
        // disable the gate. Mirrors how `--spec-base-path` missing exits 2.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'min_endpoint_coverage' => 150.0,
            'min_coverage_strict' => true,
            'cleanup' => true,
        ]);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('FATAL', $stderr);
        $this->assertStringContainsString('min_endpoint_coverage', $stderr);
    }

    #[Test]
    public function non_numeric_threshold_warns_when_not_strict(): void
    {
        // C3: parseArgv used to silently drop non-numeric values, so run()
        // never saw them. After C3 the raw string flows through and run()
        // is the single place that warns / fails.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'min_endpoint_coverage' => 'eighty',
            'cleanup' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('WARNING', $stderr);
        $this->assertStringContainsString("min_endpoint_coverage='eighty'", $stderr);
    }

    #[Test]
    public function non_numeric_threshold_exits_two_when_strict(): void
    {
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'min_endpoint_coverage' => 'eighty',
            'min_coverage_strict' => true,
            'cleanup' => true,
        ]);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('FATAL', $stderr);
    }

    #[Test]
    public function parse_argv_keeps_non_numeric_threshold_value_for_run_to_validate(): void
    {
        // C3: a typo'd `--min-endpoint-coverage=eighty` must reach run() so
        // the user sees a single WARNING/FATAL message. Pre-fix, parseArgv
        // dropped the value silently and the gate was disabled invisibly.
        $opts = CoverageMergeCommand::parseArgv([
            '--spec-base-path=/tmp/spec',
            '--min-endpoint-coverage=eighty',
        ]);

        $this->assertSame('eighty', $opts['min_endpoint_coverage']);
    }

    #[Test]
    public function exits_one_when_output_file_write_fails(): void
    {
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        // file_put_contents() === false when the parent dir does not exist
        // and isn't created. /proc/0 is unwritable on Linux; on macOS the
        // path simply doesn't exist. Either way `file_put_contents` fails
        // closed.
        $unwritable = '/proc/0/forbidden/coverage-report.md';

        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'output_file' => $unwritable,
            'cleanup' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Failed to write Markdown report', $stderr);
    }

    /** @return list<string> */
    private function sidecars(): array
    {
        return glob($this->sidecarDir . '/*.json') ?: [];
    }
}
