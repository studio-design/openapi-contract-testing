<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Studio\OpenApiContractTesting\OpenApiSpecLoader;

use function file_put_contents;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

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
    public function load_resolves_internal_refs(): void
    {
        $fixturesPath = __DIR__ . '/../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        $spec = OpenApiSpecLoader::load('refs-valid');

        // The paths schema should no longer contain '$ref' after resolution.
        $listSchema = $spec['paths']['/pets']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertSame('array', $listSchema['type']);
        $this->assertArrayNotHasKey('$ref', $listSchema['items']);
        $this->assertSame('object', $listSchema['items']['type']);

        // Transitive nested ref Pet -> Category -> Label should be fully resolved.
        $this->assertSame(
            ['type' => 'string', 'minLength' => 1],
            $listSchema['items']['properties']['category']['properties']['label'],
        );

        // Path-level parameter $ref is resolved inline.
        $pathParam = $spec['paths']['/pets/{petId}']['parameters'][0];
        $this->assertArrayNotHasKey('$ref', $pathParam);
        $this->assertSame('petId', $pathParam['name']);
        $this->assertSame('path', $pathParam['in']);

        // Response-level $ref is resolved.
        $responseSpec = $spec['paths']['/pets/{petId}']['get']['responses']['200'];
        $this->assertArrayNotHasKey('$ref', $responseSpec);
        $this->assertSame('A single pet', $responseSpec['description']);
    }

    #[Test]
    public function load_throws_on_circular_ref(): void
    {
        $fixturesPath = __DIR__ . '/../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Circular $ref');

        OpenApiSpecLoader::load('refs-circular');
    }

    #[Test]
    public function load_throws_on_external_ref(): void
    {
        $fixturesPath = __DIR__ . '/../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('External $ref');

        OpenApiSpecLoader::load('refs-external');
    }

    #[Test]
    public function load_throws_on_unresolvable_ref(): void
    {
        $fixturesPath = __DIR__ . '/../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unresolvable $ref');

        OpenApiSpecLoader::load('refs-unresolvable');
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

        // 3.1 still cached (by-value equal)
        $this->assertSame($first31, OpenApiSpecLoader::load('petstore-3.1'));

        // 3.0 reload produces the same content (by-value; array equality, not instance identity)
        $reloaded30 = OpenApiSpecLoader::load('petstore-3.0');
        $this->assertSame($first30, $reloaded30);
    }

    #[Test]
    public function failed_load_does_not_poison_cache(): void
    {
        $fixturesPath = __DIR__ . '/../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        try {
            OpenApiSpecLoader::load('refs-unresolvable');
            $this->fail('expected RuntimeException');
        } catch (RuntimeException) {
            // Expected — next load must re-attempt from disk, not return a
            // partially-resolved array captured before the throw.
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unresolvable $ref');
        OpenApiSpecLoader::load('refs-unresolvable');
    }

    #[Test]
    public function failed_load_does_not_affect_other_specs(): void
    {
        $fixturesPath = __DIR__ . '/../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        try {
            OpenApiSpecLoader::load('refs-circular');
        } catch (RuntimeException) {
            // Swallow so we can verify a different spec still loads afterwards.
        }

        // A sibling spec with clean refs must still load successfully even if
        // an earlier failure left any intermediate state behind.
        $spec = OpenApiSpecLoader::load('refs-valid');
        $this->assertSame('Refs valid', $spec['info']['title']);
    }

    #[Test]
    public function load_returns_independent_copies_of_cached_specs(): void
    {
        // Pins the PHP copy-on-write assumption that OpenApiRefResolver relies
        // on: mutating the returned array must not corrupt the cached copy.
        // Swapping to by-reference returns (or accidentally sharing the root
        // snapshot) would show up here.
        $fixturesPath = __DIR__ . '/../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        $first = OpenApiSpecLoader::load('refs-valid');
        $first['info']['title'] = 'mutated';

        $second = OpenApiSpecLoader::load('refs-valid');
        $this->assertSame('Refs valid', $second['info']['title']);
    }

    #[Test]
    public function load_parses_yaml_spec(): void
    {
        $fixturesPath = __DIR__ . '/../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        $spec = OpenApiSpecLoader::load('petstore-yaml');

        $this->assertSame('3.0.3', $spec['openapi']);
        $this->assertSame('Petstore YAML', $spec['info']['title']);
        $this->assertArrayHasKey('/v1/pets', $spec['paths']);
    }

    #[Test]
    public function load_parses_yml_spec(): void
    {
        $fixturesPath = __DIR__ . '/../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        $spec = OpenApiSpecLoader::load('petstore-yml');

        $this->assertSame('3.0.3', $spec['openapi']);
        $this->assertSame('Petstore YML', $spec['info']['title']);
    }

    #[Test]
    public function load_caches_yaml_spec(): void
    {
        $fixturesPath = __DIR__ . '/../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        $first = OpenApiSpecLoader::load('petstore-yaml');
        $second = OpenApiSpecLoader::load('petstore-yaml');

        $this->assertSame($first, $second);
    }

    #[Test]
    public function load_prefers_json_when_both_json_and_yaml_exist(): void
    {
        // Write both a .json and a .yaml for the same basename into a scratch
        // dir so the precedence guarantee in SEARCH_EXTENSIONS is covered
        // without mutating the shared tests/fixtures directory.
        $scratchDir = sys_get_temp_dir() . '/openapi-spec-loader-test-' . uniqid('', true);
        mkdir($scratchDir);

        try {
            file_put_contents(
                $scratchDir . '/dual.json',
                '{"openapi":"3.0.3","info":{"title":"JSON wins","version":"1.0.0"},"paths":{}}',
            );
            file_put_contents(
                $scratchDir . '/dual.yaml',
                "openapi: 3.0.3\ninfo:\n  title: YAML should lose\n  version: 1.0.0\npaths: {}\n",
            );

            OpenApiSpecLoader::configure($scratchDir);
            $spec = OpenApiSpecLoader::load('dual');

            $this->assertSame('JSON wins', $spec['info']['title']);
        } finally {
            @unlink($scratchDir . '/dual.json');
            @unlink($scratchDir . '/dual.yaml');
            @rmdir($scratchDir);
        }
    }

    #[Test]
    public function load_throws_when_yaml_is_malformed(): void
    {
        $fixturesPath = __DIR__ . '/../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to parse YAML');

        OpenApiSpecLoader::load('malformed-yaml');
    }

    #[Test]
    public function load_throws_when_yaml_root_is_not_an_array(): void
    {
        $fixturesPath = __DIR__ . '/../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('YAML OpenAPI spec must decode to a mapping');

        OpenApiSpecLoader::load('non-array-root');
    }

    #[Test]
    public function load_throws_when_json_root_is_not_an_array(): void
    {
        $fixturesPath = __DIR__ . '/../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JSON OpenAPI spec must decode to a mapping');

        OpenApiSpecLoader::load('non-array-json-root');
    }

    #[Test]
    public function load_throws_when_json_is_malformed(): void
    {
        $fixturesPath = __DIR__ . '/../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to parse JSON OpenAPI spec');
        $this->expectExceptionMessage('malformed-json');

        OpenApiSpecLoader::load('malformed-json');
    }

    #[Test]
    public function load_yaml_throws_with_install_hint_when_symfony_yaml_missing(): void
    {
        $fixturesPath = __DIR__ . '/../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);
        OpenApiSpecLoader::overrideYamlAvailabilityForTesting(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('symfony/yaml');
        $this->expectExceptionMessage('composer require');

        OpenApiSpecLoader::load('petstore-yaml');
    }

    #[Test]
    public function load_error_lists_all_searched_extensions_when_no_file_exists(): void
    {
        OpenApiSpecLoader::configure('/nonexistent/path');

        try {
            OpenApiSpecLoader::load('nowhere');
            $this->fail('expected RuntimeException');
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('.json', $message);
            $this->assertStringContainsString('.yaml', $message);
            $this->assertStringContainsString('.yml', $message);
            $this->assertStringContainsString('nowhere', $message);
        }
    }
}
