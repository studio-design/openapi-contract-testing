<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\PHPUnit\CoverageMergeCommand;
use Studio\OpenApiContractTesting\PHPUnit\CoverageSidecarWriter;

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
    public function applies_strip_prefixes_when_loading_specs(): void
    {
        // Worker recorded under stripped path /v1/pets — without the prefix
        // configuration the spec loader would normally still match (specs
        // store paths verbatim). The assertion here is structural: the merge
        // CLI must accept and honour `strip_prefixes` so a downstream consumer
        // that runs sequential-mode with a prefix can keep using the same
        // spec setup under parallel-mode without surprises.
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
            'strip_prefixes' => ['/api'],
            'output_file' => $this->outputFile,
            'cleanup' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertFileExists($this->outputFile);
    }

    /** @return list<string> */
    private function sidecars(): array
    {
        return glob($this->sidecarDir . '/*.json') ?: [];
    }
}
