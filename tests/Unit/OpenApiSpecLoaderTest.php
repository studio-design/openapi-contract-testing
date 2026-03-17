<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Studio\OpenApiContractTesting\OpenApiSpecLoader;

class OpenApiSpecLoaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function configure_sets_base_path_and_strip_prefixes(): void
    {
        OpenApiSpecLoader::configure('/path/to/specs', ['/api', '/internal']);

        $this->assertSame('/path/to/specs', OpenApiSpecLoader::getBasePath());
        $this->assertSame(['/api', '/internal'], OpenApiSpecLoader::getStripPrefixes());
    }

    #[Test]
    public function configure_trims_trailing_slash(): void
    {
        OpenApiSpecLoader::configure('/path/to/specs/');

        $this->assertSame('/path/to/specs', OpenApiSpecLoader::getBasePath());
    }

    #[Test]
    public function configure_defaults_strip_prefixes_to_empty(): void
    {
        OpenApiSpecLoader::configure('/path/to/specs');

        $this->assertSame([], OpenApiSpecLoader::getStripPrefixes());
    }

    #[Test]
    public function get_base_path_throws_when_not_configured(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenApiSpecLoader base path not configured');

        OpenApiSpecLoader::getBasePath();
    }

    #[Test]
    public function load_returns_parsed_spec(): void
    {
        $fixturesPath = __DIR__ . '/../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        $spec = OpenApiSpecLoader::load('petstore-3.0');

        $this->assertSame('3.0.3', $spec['openapi']);
        $this->assertSame('Petstore', $spec['info']['title']);
        $this->assertArrayHasKey('/v1/pets', $spec['paths']);
    }

    #[Test]
    public function load_caches_result(): void
    {
        $fixturesPath = __DIR__ . '/../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        $first = OpenApiSpecLoader::load('petstore-3.0');
        $second = OpenApiSpecLoader::load('petstore-3.0');

        $this->assertSame($first, $second);
    }

    #[Test]
    public function load_throws_for_nonexistent_spec(): void
    {
        OpenApiSpecLoader::configure('/nonexistent/path');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAPI bundled spec not found');

        OpenApiSpecLoader::load('nonexistent');
    }

    #[Test]
    public function reset_clears_all_state(): void
    {
        OpenApiSpecLoader::configure('/path/to/specs', ['/api']);

        OpenApiSpecLoader::reset();

        $this->assertSame([], OpenApiSpecLoader::getStripPrefixes());

        $this->expectException(RuntimeException::class);
        OpenApiSpecLoader::getBasePath();
    }

    #[Test]
    public function clear_cache_keeps_config(): void
    {
        $fixturesPath = __DIR__ . '/../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath, ['/api']);

        // Load to populate cache
        OpenApiSpecLoader::load('petstore-3.0');

        OpenApiSpecLoader::clearCache();

        // Config is preserved
        $this->assertSame($fixturesPath, OpenApiSpecLoader::getBasePath());
        $this->assertSame(['/api'], OpenApiSpecLoader::getStripPrefixes());

        // Cache is cleared — next load reads from disk again
        $spec = OpenApiSpecLoader::load('petstore-3.0');
        $this->assertSame('3.0.3', $spec['openapi']);
    }

    #[Test]
    public function evict_removes_single_spec_from_cache(): void
    {
        $fixturesPath = __DIR__ . '/../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        // Load two specs
        $first30 = OpenApiSpecLoader::load('petstore-3.0');
        $first31 = OpenApiSpecLoader::load('petstore-3.1');

        // Evict only 3.0
        OpenApiSpecLoader::evict('petstore-3.0');

        // 3.1 still cached (same reference)
        $this->assertSame($first31, OpenApiSpecLoader::load('petstore-3.1'));

        // 3.0 reloaded from disk (equal but fresh instance)
        $reloaded30 = OpenApiSpecLoader::load('petstore-3.0');
        $this->assertSame($first30, $reloaded30);
    }
}
