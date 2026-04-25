<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Internal\ExternalRefLoader;
use Studio\OpenApiContractTesting\Internal\YamlAvailability;
use Studio\OpenApiContractTesting\InvalidOpenApiSpecException;
use Studio\OpenApiContractTesting\InvalidOpenApiSpecReason;

use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class ExternalRefLoaderTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = sys_get_temp_dir() . '/oct-extref-' . uniqid();
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->workDir);
        YamlAvailability::reset();
        parent::tearDown();
    }

    #[Test]
    public function loads_relative_json_file_relative_to_source_file(): void
    {
        $sourceFile = $this->workDir . '/root.yaml';
        file_put_contents($sourceFile, "openapi: 3.0.3\n");
        file_put_contents($this->workDir . '/pet.json', '{"type":"object","required":["id"]}');

        $cache = [];
        $result = ExternalRefLoader::loadDocument('./pet.json', $sourceFile, $cache);

        $this->assertSame(['type' => 'object', 'required' => ['id']], $result['decoded']);
        $this->assertStringEndsWith('/pet.json', $result['absolutePath']);
    }

    #[Test]
    public function loads_relative_yaml_file(): void
    {
        $sourceFile = $this->workDir . '/root.json';
        file_put_contents($sourceFile, '{}');
        file_put_contents($this->workDir . '/pet.yaml', "type: object\nrequired:\n  - id\n");

        $cache = [];
        $result = ExternalRefLoader::loadDocument('./pet.yaml', $sourceFile, $cache);

        $this->assertSame(['type' => 'object', 'required' => ['id']], $result['decoded']);
    }

    #[Test]
    public function resolves_parent_directory_relative_paths(): void
    {
        mkdir($this->workDir . '/sub');
        $sourceFile = $this->workDir . '/sub/root.yaml';
        file_put_contents($sourceFile, "openapi: 3.0.3\n");
        file_put_contents($this->workDir . '/shared.json', '{"name":"shared"}');

        $cache = [];
        $result = ExternalRefLoader::loadDocument('../shared.json', $sourceFile, $cache);

        $this->assertSame(['name' => 'shared'], $result['decoded']);
    }

    #[Test]
    public function loads_absolute_path_directly(): void
    {
        $absolute = $this->workDir . '/abs.json';
        file_put_contents($absolute, '{"absolute":true}');

        $cache = [];
        $result = ExternalRefLoader::loadDocument($absolute, $this->workDir . '/unused.yaml', $cache);

        $this->assertSame(['absolute' => true], $result['decoded']);
    }

    #[Test]
    public function caches_loaded_documents_within_a_call(): void
    {
        $sourceFile = $this->workDir . '/root.yaml';
        file_put_contents($sourceFile, "openapi: 3.0.3\n");
        $target = $this->workDir . '/pet.json';
        file_put_contents($target, '{"type":"object"}');

        $cache = [];
        ExternalRefLoader::loadDocument('./pet.json', $sourceFile, $cache);

        // Mutate the file on disk; cached result should still be returned.
        file_put_contents($target, '{"type":"string"}');
        $second = ExternalRefLoader::loadDocument('./pet.json', $sourceFile, $cache);

        $this->assertSame(['type' => 'object'], $second['decoded']);
    }

    #[Test]
    public function throws_local_ref_not_found_when_file_missing(): void
    {
        $sourceFile = $this->workDir . '/root.yaml';
        file_put_contents($sourceFile, "openapi: 3.0.3\n");

        try {
            $cache = [];
            ExternalRefLoader::loadDocument('./missing.yaml', $sourceFile, $cache);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::LocalRefNotFound, $e->reason);
            $this->assertStringContainsString('./missing.yaml', $e->getMessage());
        }
    }

    #[Test]
    public function throws_local_ref_decode_failed_on_malformed_json(): void
    {
        $sourceFile = $this->workDir . '/root.yaml';
        file_put_contents($sourceFile, "openapi: 3.0.3\n");
        file_put_contents($this->workDir . '/bad.json', '{ not valid json');

        try {
            $cache = [];
            ExternalRefLoader::loadDocument('./bad.json', $sourceFile, $cache);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::LocalRefDecodeFailed, $e->reason);
            $this->assertStringContainsString('./bad.json', $e->getMessage());
        }
    }

    #[Test]
    public function throws_local_ref_decode_failed_on_malformed_yaml(): void
    {
        $sourceFile = $this->workDir . '/root.json';
        file_put_contents($sourceFile, '{}');
        file_put_contents($this->workDir . '/bad.yaml', "key: value\n  bad: indent\n");

        try {
            $cache = [];
            ExternalRefLoader::loadDocument('./bad.yaml', $sourceFile, $cache);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::LocalRefDecodeFailed, $e->reason);
        }
    }

    #[Test]
    public function throws_unsupported_extension_for_unknown_format(): void
    {
        $sourceFile = $this->workDir . '/root.yaml';
        file_put_contents($sourceFile, "openapi: 3.0.3\n");
        file_put_contents($this->workDir . '/pet.txt', 'not a spec');

        try {
            $cache = [];
            ExternalRefLoader::loadDocument('./pet.txt', $sourceFile, $cache);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::UnsupportedExtension, $e->reason);
            $this->assertStringContainsString('.txt', $e->getMessage());
        }
    }

    #[Test]
    public function throws_yaml_library_missing_when_loading_yaml_without_symfony_yaml(): void
    {
        $sourceFile = $this->workDir . '/root.json';
        file_put_contents($sourceFile, '{}');
        file_put_contents($this->workDir . '/pet.yaml', "type: object\n");

        YamlAvailability::overrideForTesting(false);

        try {
            $cache = [];
            ExternalRefLoader::loadDocument('./pet.yaml', $sourceFile, $cache);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::YamlLibraryMissing, $e->reason);
        }
    }

    #[Test]
    public function throws_when_decoded_root_is_not_a_mapping(): void
    {
        $sourceFile = $this->workDir . '/root.yaml';
        file_put_contents($sourceFile, "openapi: 3.0.3\n");
        // A bare scalar at the root is valid JSON but cannot be a $ref target.
        file_put_contents($this->workDir . '/scalar.json', '"just a string"');

        try {
            $cache = [];
            ExternalRefLoader::loadDocument('./scalar.json', $sourceFile, $cache);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::NonMappingRoot, $e->reason);
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
