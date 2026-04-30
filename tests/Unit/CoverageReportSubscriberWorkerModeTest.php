<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Studio\OpenApiContractTesting\Coverage\CoverageSidecarReader;
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\PHPUnit\ConsoleOutput;
use Studio\OpenApiContractTesting\PHPUnit\CoverageReportSubscriber;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;
use Throwable;

use function file_get_contents;
use function file_put_contents;
use function getenv;
use function getmypid;
use function glob;
use function is_dir;
use function json_decode;
use function mkdir;
use function ob_get_clean;
use function ob_start;
use function putenv;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Pins the paratest worker-mode contract on {@see CoverageReportSubscriber}:
 * when `TEST_TOKEN` is set the subscriber writes a sidecar and emits NO
 * stdout / output_file / GITHUB_STEP_SUMMARY output. When `TEST_TOKEN` is
 * unset the subscriber renders normally — sequential PHPUnit must not be
 * affected by these changes.
 */
class CoverageReportSubscriberWorkerModeTest extends TestCase
{
    private string $tmpDir = '';
    private ?string $previousTestToken;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');

        $this->tmpDir = sys_get_temp_dir() . '/openapi-coverage-subscriber-' . uniqid('', true);

        // Snapshot whatever TEST_TOKEN was set to (some CI runners pre-set it).
        $current = getenv('TEST_TOKEN');
        $this->previousTestToken = $current === false ? null : $current;
        putenv('TEST_TOKEN');
    }

    protected function tearDown(): void
    {
        if ($this->previousTestToken === null) {
            putenv('TEST_TOKEN');
        } else {
            putenv('TEST_TOKEN=' . $this->previousTestToken);
        }

        if (is_dir($this->tmpDir)) {
            $entries = glob($this->tmpDir . '/*') ?: [];
            foreach ($entries as $entry) {
                @unlink($entry);
            }
            @rmdir($this->tmpDir);
        }

        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function worker_mode_writes_sidecar_and_skips_rendering(): void
    {
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        putenv('TEST_TOKEN=4');

        $subscriber = new CoverageReportSubscriber(
            specs: ['petstore-3.0'],
            outputFile: $this->tmpDir . '/coverage-report.md',
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            sidecarDir: $this->tmpDir,
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        $stdout = (string) ob_get_clean();

        $this->assertSame('', $stdout, 'worker mode must not emit console output');
        $this->assertFileDoesNotExist($this->tmpDir . '/coverage-report.md', 'worker mode must not write output_file');

        $loaded = CoverageSidecarReader::readDir($this->tmpDir);
        $this->assertCount(1, $loaded);
        $this->assertSame(1, $loaded[0]['version']);
        $this->assertArrayHasKey('petstore-3.0', $loaded[0]['specs']);
    }

    #[Test]
    public function worker_mode_writes_empty_sidecar_when_no_coverage_recorded(): void
    {
        // Pinning that workers always emit a sidecar — even an empty one —
        // so the merge CLI never silently misses a worker that happened to
        // run no contract assertions. Skipping the write here would make
        // "0 tests touched the spec" indistinguishable from "the worker
        // crashed before writing".
        putenv('TEST_TOKEN=2');

        $subscriber = new CoverageReportSubscriber(
            specs: ['petstore-3.0'],
            outputFile: null,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            sidecarDir: $this->tmpDir,
        );

        $subscriber->notify($this->fakeExecutionFinished());

        $loaded = CoverageSidecarReader::readDir($this->tmpDir);
        $this->assertCount(1, $loaded);
        $this->assertSame([], $loaded[0]['specs']);
    }

    #[Test]
    public function sequential_mode_renders_as_before_when_test_token_unset(): void
    {
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );

        $outputFile = $this->tmpDir . '/coverage-report.md';
        mkdir($this->tmpDir, 0o755, recursive: true);

        $subscriber = new CoverageReportSubscriber(
            specs: ['petstore-3.0'],
            outputFile: $outputFile,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            sidecarDir: $this->tmpDir,
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        $stdout = (string) ob_get_clean();

        $this->assertNotSame('', $stdout, 'sequential mode must render to stdout');
        $this->assertFileExists($outputFile, 'sequential mode must write output_file');
        $this->assertSame([], CoverageSidecarReader::readDir($this->tmpDir));
    }

    #[Test]
    public function worker_mode_keeps_running_when_sidecar_write_fails(): void
    {
        // The contract assertion that triggered notify() must never be
        // demoted to a hard failure by a sidecar I/O error — the user's
        // tests already passed; sidecar trouble is a CI artifact concern.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        putenv('TEST_TOKEN=1');

        // Pass a path that points at an existing FILE, not a directory, so
        // mkdir/rename both fail. The subscriber must catch, warn, and
        // (per C2) drop a failure marker the merge CLI can detect.
        $blocker = sys_get_temp_dir() . '/openapi-coverage-blocker-' . uniqid('', true);
        file_put_contents($blocker, 'not a dir');

        $stderrLog = '';
        $subscriber = new CoverageReportSubscriber(
            specs: ['petstore-3.0'],
            outputFile: null,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            sidecarDir: $blocker,
            stderrWriter: static function (string $msg) use (&$stderrLog): void {
                $stderrLog .= $msg;
            },
        );

        $exception = null;

        try {
            $subscriber->notify($this->fakeExecutionFinished());
        } catch (Throwable $e) {
            $exception = $e;
        } finally {
            @unlink($blocker);
        }

        $this->assertNull($exception, 'sidecar write failure must NOT bubble out of notify()');
        $this->assertStringContainsString('WARNING', $stderrLog);
        $this->assertStringContainsString('sidecar', $stderrLog);
    }

    #[Test]
    public function worker_mode_writes_failure_marker_when_sidecar_write_fails(): void
    {
        // C2: when the sidecar write fails, the worker drops a
        // `failed-<token>.json` marker in the sidecar dir so the merge CLI
        // can detect a missing worker and exit non-zero. Without the
        // marker, the merge would silently under-count coverage.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        putenv('TEST_TOKEN=7');

        // sidecarDir is writable but the sidecar write itself fails by
        // pointing the writer at a target whose `*.tmp` rename target is
        // a directory rather than a file. We simulate this more simply
        // by pre-occupying the final filename with a directory.
        mkdir($this->tmpDir, 0o755, recursive: true);
        $expectedSidecar = $this->tmpDir . '/part-7-' . (string) getmypid() . '.json';
        mkdir($expectedSidecar, 0o755);

        $subscriber = new CoverageReportSubscriber(
            specs: ['petstore-3.0'],
            outputFile: null,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            sidecarDir: $this->tmpDir,
            stderrWriter: static fn(string $msg): null => null,
        );

        $subscriber->notify($this->fakeExecutionFinished());

        $markerPath = $this->tmpDir . '/failed-7-' . (string) getmypid() . '.json';
        $this->assertFileExists($markerPath, 'C2: failure marker must be dropped');
        $payload = json_decode((string) file_get_contents($markerPath), true);
        $this->assertSame('7', $payload['testToken']);
        $this->assertNotEmpty($payload['reason']);

        @unlink($markerPath);
        @rmdir($expectedSidecar);
    }

    /**
     * Build a stub `ExecutionFinished` without invoking its constructor. The
     * subscriber's `notify()` does not read the event, so a structurally
     * valid stand-in is enough — and the real Telemetry constructor signature
     * has churned across PHPUnit 11/12/13, which would force version-gated
     * stub builders that add no test value.
     */
    private function fakeExecutionFinished(): ExecutionFinished
    {
        return (new ReflectionClass(ExecutionFinished::class))->newInstanceWithoutConstructor();
    }
}
