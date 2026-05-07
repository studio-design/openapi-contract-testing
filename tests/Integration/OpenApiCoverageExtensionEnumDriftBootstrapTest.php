<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Integration;

use const ENT_QUOTES;
use const ENT_XML1;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function escapeshellarg;
use function fclose;
use function file_put_contents;
use function htmlspecialchars;
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
 * End-to-end coverage of the auto-discovery wiring: the unit tests prove
 * `setupExtension()` writes the right blocks, this one proves PHPUnit's real
 * bootstrapper turns those blocks into the right exit code so CI fails (or
 * doesn't) as advertised.
 */
class OpenApiCoverageExtensionEnumDriftBootstrapTest extends TestCase
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
    public function phpunit_exits_non_zero_when_auto_discovered_drift_is_strict(): void
    {
        [$exit, $output] = $this->runPhpunit([
            'enum_drift_enabled' => 'true',
            'enum_drift_scan_namespaces' =>
                'Studio\\OpenApiContractTesting\\Tests\\Integration\\Schema\\Fixture\\AutoDiscovery\\Drifting\\',
        ]);

        $this->assertNotSame(0, $exit, "Expected non-zero exit; output was:\n" . $output);
        $this->assertStringContainsString('[OpenAPI Enum Drift] FATAL', $output);
        $this->assertStringContainsString('AutoDiscoveryDriftingEnum', $output);
    }

    #[Test]
    public function phpunit_exits_zero_when_auto_discovered_drift_is_lenient(): void
    {
        [$exit, $output] = $this->runPhpunit([
            'enum_drift_enabled' => 'true',
            'enum_drift_scan_namespaces' =>
                'Studio\\OpenApiContractTesting\\Tests\\Integration\\Schema\\Fixture\\AutoDiscovery\\Drifting\\',
            'enum_drift_fail_on_drift' => 'false',
        ]);

        $this->assertSame(0, $exit, "Expected zero exit; output was:\n" . $output);
        $this->assertStringContainsString('[OpenAPI Enum Drift] WARNING', $output);
        $this->assertStringContainsString('AutoDiscoveryDriftingEnum', $output);
    }

    #[Test]
    public function phpunit_exits_zero_when_auto_discovered_enums_have_no_drift(): void
    {
        [$exit, $output] = $this->runPhpunit([
            'enum_drift_enabled' => 'true',
            'enum_drift_scan_namespaces' =>
                'Studio\\OpenApiContractTesting\\Tests\\Integration\\Schema\\Fixture\\AutoDiscovery\\Clean\\',
        ]);

        $this->assertSame(0, $exit, "Expected zero exit; output was:\n" . $output);
        $this->assertStringNotContainsString('[OpenAPI Enum Drift]', $output);
    }

    #[Test]
    public function phpunit_exits_non_zero_when_namespace_is_unresolvable(): void
    {
        [$exit, $output] = $this->runPhpunit([
            'enum_drift_enabled' => 'true',
            'enum_drift_scan_namespaces' => 'Some\\Nonexistent\\Namespace\\',
        ]);

        $this->assertNotSame(0, $exit, "Expected non-zero exit; output was:\n" . $output);
        $this->assertStringContainsString('[OpenAPI Enum Drift] FATAL', $output);
        $this->assertStringContainsString('Some\\Nonexistent\\Namespace\\', $output);
    }

    #[Test]
    public function phpunit_exits_non_zero_when_drift_enabled_with_no_namespaces(): void
    {
        [$exit, $output] = $this->runPhpunit([
            'enum_drift_enabled' => 'true',
        ]);

        $this->assertNotSame(0, $exit, "Expected non-zero exit; output was:\n" . $output);
        $this->assertStringContainsString('[OpenAPI Enum Drift] FATAL', $output);
        $this->assertStringContainsString('enum_drift_scan_namespaces', $output);
    }

    /**
     * @param array<string, string> $extensionParameters
     *
     * @return array{0: int, 1: string} [exit code, combined stderr+stdout]
     */
    private function runPhpunit(array $extensionParameters): array
    {
        $paramXml = '';
        foreach ($extensionParameters as $name => $value) {
            $paramXml .= sprintf(
                '<parameter name="%s" value="%s"/>',
                htmlspecialchars($name, ENT_XML1 | ENT_QUOTES),
                htmlspecialchars($value, ENT_XML1 | ENT_QUOTES),
            );
        }

        $xml = sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<phpunit bootstrap="%s/vendor/autoload.php" cacheDirectory="%s/.phpunit.cache" colors="false">'
            . '<testsuites><testsuite name="Smoke"><directory>%s/tests/Integration/Fixture/Smoke</directory></testsuite></testsuites>'
            . '<extensions><bootstrap class="Studio\OpenApiContractTesting\PHPUnit\OpenApiCoverageExtension">'
            . '<parameter name="spec_base_path" value="%s/tests/fixtures/specs"/>'
            . '<parameter name="specs" value="refs-valid"/>'
            . '%s'
            . '</bootstrap></extensions>'
            . '</phpunit>',
            $this->repoRoot,
            $this->repoRoot,
            $this->repoRoot,
            $this->repoRoot,
            $paramXml,
        );

        $tmp = tempnam(sys_get_temp_dir(), 'openapi-enum-drift-integration-') ?: null;
        if ($tmp === null) {
            $this->fail('Could not create temp phpunit config');
        }
        $this->configPath = $tmp;
        file_put_contents($tmp, $xml);

        $cmd = sprintf(
            '%s/vendor/bin/phpunit -c %s',
            escapeshellarg($this->repoRoot),
            escapeshellarg($tmp),
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

        return [$exit, $stdout . $stderr];
    }
}
