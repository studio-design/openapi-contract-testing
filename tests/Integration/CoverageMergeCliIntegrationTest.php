<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\PHPUnit\CoverageSidecarWriter;

use function dirname;
use function escapeshellarg;
use function fclose;
use function file_exists;
use function file_get_contents;
use function glob;
use function is_resource;
use function mkdir;
use function proc_close;
use function proc_open;
use function realpath;
use function rmdir;
use function sprintf;
use function stream_get_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Spawns the real `bin/openapi-coverage-merge` against fixture sidecars and
 * asserts the combined Markdown report it produces. The unit tests cover
 * `CoverageMergeCommand::run()` directly; this test pins the bin shim
 * (autoload discovery, argv parsing, exit code) on the actual filesystem so
 * a downstream `composer require` install path keeps working.
 */
class CoverageMergeCliIntegrationTest extends TestCase
{
    private string $repoRoot = '';
    private string $sidecarDir = '';
    private string $outputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();

        $this->repoRoot = realpath(__DIR__ . '/../..') ?: __DIR__ . '/../..';
        $base = sys_get_temp_dir() . '/openapi-coverage-merge-cli-' . uniqid('', true);
        $this->sidecarDir = $base . '/sidecars';
        $this->outputFile = $base . '/coverage-report.md';
        mkdir($this->sidecarDir, 0o755, recursive: true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->sidecarDir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        if (file_exists($this->outputFile)) {
            @unlink($this->outputFile);
        }
        @rmdir($this->sidecarDir);
        @rmdir(dirname($this->sidecarDir));

        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function bin_merges_two_worker_sidecars_into_one_report(): void
    {
        // Worker 1.
        OpenApiSpecLoader::configure($this->repoRoot . '/tests/fixtures/specs');
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        // Worker 2.
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

        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();

        [$exit, $stdout, $stderr] = $this->runCli([
            '--spec-base-path=' . $this->repoRoot . '/tests/fixtures/specs',
            '--specs=petstore-3.0',
            '--sidecar-dir=' . $this->sidecarDir,
            '--output-file=' . $this->outputFile,
        ]);

        $this->assertSame(0, $exit, "stderr: {$stderr}\nstdout: {$stdout}");
        $this->assertFileExists($this->outputFile);
        $contents = (string) file_get_contents($this->outputFile);
        $this->assertStringContainsString('GET /v1/pets', $contents);
        $this->assertStringContainsString('POST /v1/pets', $contents);
    }

    #[Test]
    public function bin_returns_zero_when_no_sidecars_present(): void
    {
        [$exit, , $stderr] = $this->runCli([
            '--spec-base-path=' . $this->repoRoot . '/tests/fixtures/specs',
            '--specs=petstore-3.0',
            '--sidecar-dir=' . $this->sidecarDir,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('no sidecars found', $stderr);
    }

    #[Test]
    public function bin_help_flag_prints_usage_and_exits_zero(): void
    {
        [$exit, $stdout] = $this->runCli(['--help']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('openapi-coverage-merge', $stdout);
        $this->assertStringContainsString('--spec-base-path', $stdout);
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function runCli(array $args): array
    {
        $cmd = sprintf('php %s', escapeshellarg($this->repoRoot . '/bin/openapi-coverage-merge'));
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $process = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->repoRoot,
        );
        if (!is_resource($process)) {
            $this->fail('failed to spawn merge CLI');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        $exit = proc_close($process);

        return [$exit, $stdout, $stderr];
    }
}
