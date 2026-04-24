<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function escapeshellarg;
use function fclose;
use function file_put_contents;
use function is_resource;
use function proc_close;
use function proc_open;
use function realpath;
use function sprintf;
use function stream_get_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Exercises the real `bootstrap()` -> `exit(1)` path by spawning a child
 * PHPUnit process. The unit tests cover `setupExtension()` directly, which
 * bypasses the wrapper; this test pins the observable contract — broken
 * spec → non-zero PHPUnit exit — that the PR exists to guarantee.
 */
class OpenApiCoverageExtensionBootstrapTest extends TestCase
{
    private string $repoRoot;
    private ?string $configPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repoRoot = realpath(__DIR__ . '/../..') ?: __DIR__ . '/../..';
    }

    protected function tearDown(): void
    {
        if ($this->configPath !== null) {
            @unlink($this->configPath);
            $this->configPath = null;
        }
        parent::tearDown();
    }

    #[Test]
    public function phpunit_exits_non_zero_when_registered_spec_has_unresolvable_ref(): void
    {
        // Filter matches no real tests so we observe the bootstrap-only path:
        // if the suite exited 0 here, the hard-fail contract is broken.
        [$exit, $stderr] = $this->runPhpunit('refs-unresolvable', '--filter=DoesNotMatchAnyTest');

        $this->assertNotSame(0, $exit, "Expected non-zero exit; stderr was:\n" . $stderr);
        $this->assertStringContainsString('FATAL', $stderr);
        $this->assertStringContainsString('refs-unresolvable', $stderr);
    }

    #[Test]
    public function phpunit_exits_zero_when_registered_spec_file_is_missing(): void
    {
        // `OpenApiVersionTest` is a lightweight, always-present target so the
        // suite has something to pass; the stale `specs=` entry must degrade
        // to a warning without aborting.
        [$exit, $stderr] = $this->runPhpunit('does-not-exist', '--filter=OpenApiVersionTest');

        $this->assertSame(0, $exit, "Expected exit 0; stderr was:\n" . $stderr);
        $this->assertStringContainsString('WARNING', $stderr);
        $this->assertStringContainsString('does-not-exist', $stderr);
    }

    /**
     * @return array{0: int, 1: string} [exit code, combined stderr]
     */
    private function runPhpunit(string $specsParam, string $filterArg): array
    {
        $xml = sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<phpunit bootstrap="%s/vendor/autoload.php" cacheDirectory="%s/.phpunit.cache" colors="false">'
            . '<testsuites><testsuite name="Unit"><directory>%s/tests/Unit</directory></testsuite></testsuites>'
            . '<extensions><bootstrap class="Studio\OpenApiContractTesting\PHPUnit\OpenApiCoverageExtension">'
            . '<parameter name="spec_base_path" value="%s/tests/fixtures/specs"/>'
            . '<parameter name="specs" value="%s"/>'
            . '</bootstrap></extensions>'
            . '</phpunit>',
            $this->repoRoot,
            $this->repoRoot,
            $this->repoRoot,
            $this->repoRoot,
            $specsParam,
        );

        $tmp = tempnam(sys_get_temp_dir(), 'openapi-ext-integration-') ?: null;
        if ($tmp === null) {
            $this->fail('Could not create temp phpunit config');
        }
        $this->configPath = $tmp;
        file_put_contents($tmp, $xml);

        $cmd = sprintf(
            '%s/vendor/bin/phpunit -c %s %s',
            escapeshellarg($this->repoRoot),
            escapeshellarg($tmp),
            escapeshellarg($filterArg),
        );

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptors, $pipes, $this->repoRoot);
        if (!is_resource($process)) {
            $this->fail('Could not spawn phpunit subprocess');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        $exit = proc_close($process);

        // Both streams may carry the FATAL/WARNING line depending on where
        // PHP flushes; the caller only cares about the combined text.
        return [$exit, $stdout . $stderr];
    }
}
