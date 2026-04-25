<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Studio\OpenApiContractTesting\InvalidOpenApiSpecException;
use Studio\OpenApiContractTesting\InvalidOpenApiSpecReason;
use Studio\OpenApiContractTesting\OpenApiRefResolver;
use Studio\OpenApiContractTesting\Tests\Helpers\FakeHttpClient;

use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class OpenApiRefResolverHttpRefsTest extends TestCase
{
    private HttpFactory $factory;
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new HttpFactory();
        $this->workDir = sys_get_temp_dir() . '/oct-resolver-http-' . uniqid();
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->workDir);
        parent::tearDown();
    }

    #[Test]
    public function resolves_https_ref_when_opt_in_and_client_provided(): void
    {
        $url = 'https://example.com/schemas/pet.json';
        $client = new FakeHttpClient([
            $url => FakeHttpClient::jsonResponse('{"type":"object","required":["id"]}'),
        ]);

        $spec = ['components' => ['schemas' => ['Pet' => ['$ref' => $url]]]];

        $resolved = OpenApiRefResolver::resolve(
            $spec,
            sourceFile: null,
            httpClient: $client,
            requestFactory: $this->factory,
            allowRemoteRefs: true,
        );

        $this->assertSame(
            ['type' => 'object', 'required' => ['id']],
            $resolved['components']['schemas']['Pet'],
        );
    }

    #[Test]
    public function resolves_https_ref_with_json_pointer_fragment(): void
    {
        $url = 'https://example.com/schemas.json';
        $client = new FakeHttpClient([
            $url => FakeHttpClient::jsonResponse(
                '{"Pet":{"type":"object"},"Owner":{"type":"string"}}',
            ),
        ]);

        $spec = ['components' => ['schemas' => ['Pet' => ['$ref' => $url . '#/Pet']]]];

        $resolved = OpenApiRefResolver::resolve(
            $spec,
            httpClient: $client,
            requestFactory: $this->factory,
            allowRemoteRefs: true,
        );

        $this->assertSame(['type' => 'object'], $resolved['components']['schemas']['Pet']);
    }

    #[Test]
    public function resolves_chain_of_https_refs(): void
    {
        $a = 'https://example.com/a.json';
        $b = 'https://example.com/b.json';
        $c = 'https://example.com/c.json';
        $client = new FakeHttpClient([
            $a => FakeHttpClient::jsonResponse('{"$ref":"' . $b . '"}'),
            $b => FakeHttpClient::jsonResponse('{"$ref":"' . $c . '"}'),
            $c => FakeHttpClient::jsonResponse('{"type":"string"}'),
        ]);

        $spec = ['components' => ['schemas' => ['Leaf' => ['$ref' => $a]]]];

        $resolved = OpenApiRefResolver::resolve(
            $spec,
            httpClient: $client,
            requestFactory: $this->factory,
            allowRemoteRefs: true,
        );

        $this->assertSame(['type' => 'string'], $resolved['components']['schemas']['Leaf']);
    }

    #[Test]
    public function detects_cycle_between_two_https_refs(): void
    {
        $a = 'https://example.com/a.json';
        $b = 'https://example.com/b.json';
        $client = new FakeHttpClient([
            $a => FakeHttpClient::jsonResponse('{"$ref":"' . $b . '#/Loop"}'),
            $b => FakeHttpClient::jsonResponse('{"Loop":{"$ref":"' . $a . '"}}'),
        ]);

        $spec = ['components' => ['schemas' => ['Start' => ['$ref' => $a]]]];

        try {
            OpenApiRefResolver::resolve(
                $spec,
                httpClient: $client,
                requestFactory: $this->factory,
                allowRemoteRefs: true,
            );
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::CircularRef, $e->reason);
        }
    }

    #[Test]
    public function resolves_cross_source_local_to_remote_chain(): void
    {
        // Local root → local file → remote URL → leaf. Mirrors a real
        // hybrid setup where some schemas live in the repo and others are
        // pulled from a Schema Registry.
        $rootPath = $this->workDir . '/openapi.json';
        file_put_contents($rootPath, '{}');
        file_put_contents(
            $this->workDir . '/local-bridge.json',
            '{"$ref":"https://example.com/leaf.json"}',
        );

        $client = new FakeHttpClient([
            'https://example.com/leaf.json' => FakeHttpClient::jsonResponse('{"type":"integer"}'),
        ]);

        $spec = ['components' => ['schemas' => ['Final' => ['$ref' => './local-bridge.json']]]];

        $resolved = OpenApiRefResolver::resolve(
            $spec,
            sourceFile: $rootPath,
            httpClient: $client,
            requestFactory: $this->factory,
            allowRemoteRefs: true,
        );

        $this->assertSame(['type' => 'integer'], $resolved['components']['schemas']['Final']);
    }

    #[Test]
    public function reuses_loaded_url_for_sibling_fragment_refs(): void
    {
        $url = 'https://example.com/schemas.json';
        $callCount = 0;
        $client = new FakeHttpClient([
            $url => static function (RequestInterface $request) use (&$callCount): Response {
                $callCount++;

                return new Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    '{"Pet":{"type":"object"},"Owner":{"type":"string"}}',
                );
            },
        ]);

        $spec = [
            'components' => [
                'schemas' => [
                    'Pet' => ['$ref' => $url . '#/Pet'],
                    'Owner' => ['$ref' => $url . '#/Owner'],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve(
            $spec,
            httpClient: $client,
            requestFactory: $this->factory,
            allowRemoteRefs: true,
        );

        $this->assertSame(1, $callCount, 'duplicate URLs should hit the per-resolution cache');
        $this->assertSame(['type' => 'object'], $resolved['components']['schemas']['Pet']);
        $this->assertSame(['type' => 'string'], $resolved['components']['schemas']['Owner']);
    }

    #[Test]
    public function throws_remote_ref_disallowed_when_allow_flag_is_off_even_with_client(): void
    {
        $url = 'https://example.com/pet.json';
        $client = new FakeHttpClient([
            $url => FakeHttpClient::jsonResponse('{"type":"object"}'),
        ]);

        $spec = ['components' => ['schemas' => ['Pet' => ['$ref' => $url]]]];

        try {
            OpenApiRefResolver::resolve(
                $spec,
                httpClient: $client,
                requestFactory: $this->factory,
                allowRemoteRefs: false,
            );
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::RemoteRefDisallowed, $e->reason);
        }

        $this->assertSame([], $client->sentUrls(), 'client must not be invoked when flag is off');
    }

    #[Test]
    public function throws_http_client_not_configured_when_flag_on_but_client_missing(): void
    {
        $spec = ['components' => ['schemas' => ['Pet' => ['$ref' => 'https://example.com/pet.json']]]];

        try {
            OpenApiRefResolver::resolve($spec, allowRemoteRefs: true);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::HttpClientNotConfigured, $e->reason);
            $this->assertStringContainsString('PSR-18', $e->getMessage());
        }
    }

    #[Test]
    public function throws_http_client_not_configured_when_flag_on_but_factory_missing(): void
    {
        $client = new FakeHttpClient();
        $spec = ['components' => ['schemas' => ['Pet' => ['$ref' => 'https://example.com/pet.json']]]];

        try {
            OpenApiRefResolver::resolve(
                $spec,
                httpClient: $client,
                requestFactory: null,
                allowRemoteRefs: true,
            );
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::HttpClientNotConfigured, $e->reason);
        }
    }

    #[Test]
    public function decodes_json_pointer_escapes_in_remote_fragment(): void
    {
        $url = 'https://example.com/schemas.json';
        $client = new FakeHttpClient([
            $url => FakeHttpClient::jsonResponse('{"a/b":{"type":"object"}}'),
        ]);

        $spec = ['components' => ['schemas' => ['Slash' => ['$ref' => $url . '#/a~1b']]]];

        $resolved = OpenApiRefResolver::resolve(
            $spec,
            httpClient: $client,
            requestFactory: $this->factory,
            allowRemoteRefs: true,
        );

        $this->assertSame(['type' => 'object'], $resolved['components']['schemas']['Slash']);
    }

    #[Test]
    public function throws_unresolvable_ref_when_remote_fragment_missing(): void
    {
        $url = 'https://example.com/schemas.json';
        $client = new FakeHttpClient([
            $url => FakeHttpClient::jsonResponse('{"Pet":{"type":"object"}}'),
        ]);

        $spec = ['components' => ['schemas' => ['Missing' => ['$ref' => $url . '#/Owner']]]];

        try {
            OpenApiRefResolver::resolve(
                $spec,
                httpClient: $client,
                requestFactory: $this->factory,
                allowRemoteRefs: true,
            );
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::UnresolvableRef, $e->reason);
        }
    }

    #[Test]
    public function throws_bare_fragment_for_trailing_hash_on_https_ref(): void
    {
        $url = 'https://example.com/pet.json';
        $client = new FakeHttpClient();

        $spec = ['components' => ['schemas' => ['Pet' => ['$ref' => $url . '#']]]];

        try {
            OpenApiRefResolver::resolve(
                $spec,
                httpClient: $client,
                requestFactory: $this->factory,
                allowRemoteRefs: true,
            );
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::BareFragmentRef, $e->reason);
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
