<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Compatibility;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function dirname;
use function file_get_contents;
use function json_decode;
use function sort;
use function str_contains;

final class DiagnosticPrefixesBaselineTest extends TestCase
{
    #[Test]
    public function diagnostic_prefixes_match_the_v1_9_inventory(): void
    {
        /** @var array{
         *     identity_neutral_prefixes: list<string>,
         *     legacy_branded_prefix_occurrences: array<string, list<string>>
         * } $fixture
         */
        $fixture = json_decode(
            $this->fixture(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $sources = $this->sourceFiles();

        foreach ($fixture['identity_neutral_prefixes'] as $prefix) {
            $this->assertTrue(
                $this->sourceContains($sources, $prefix),
                "Expected the v1 diagnostic prefix {$prefix} to remain present.",
            );
        }

        foreach ($fixture['legacy_branded_prefix_occurrences'] as $prefix => $expectedFiles) {
            $actualFiles = [];
            foreach ($sources as $file => $contents) {
                if (str_contains($contents, $prefix)) {
                    $actualFiles[] = $file;
                }
            }

            sort($actualFiles);
            sort($expectedFiles);
            $this->assertSame($expectedFiles, $actualFiles);
        }
    }

    /** @param array<string, string> $sources */
    private function sourceContains(array $sources, string $prefix): bool
    {
        foreach ($sources as $contents) {
            if (str_contains($contents, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> */
    private function sourceFiles(): array
    {
        $projectRoot = dirname(__DIR__, 3);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($projectRoot . '/src'),
        );
        $sources = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            $this->assertIsString($contents);
            $relativePath = 'src/' . $iterator->getSubPathname();
            $sources[$relativePath] = $contents;
        }

        return $sources;
    }

    private function fixture(): string
    {
        $contents = file_get_contents(
            dirname(__DIR__, 2) . '/fixtures/compatibility/v1.9-diagnostic-prefixes.json',
        );
        $this->assertIsString($contents);

        return $contents;
    }
}
