<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Internal;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Studio\OpenApiContractTesting\Internal\HttpRefLoader;
use Studio\OpenApiContractTesting\InvalidOpenApiSpecException;
use Studio\OpenApiContractTesting\InvalidOpenApiSpecReason;
use Studio\OpenApiContractTesting\Tests\Helpers\FakeHttpClient;
use Studio\OpenApiContractTesting\Tests\Helpers\FakeHttpClientUnexpectedRequest;

class HttpRefLoaderTest extends TestCase
{
    private HttpFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new HttpFactory();
    }

    #[Test]
    public function fetches_and_decodes_json_via_url_extension(): void
    {
        $url = 'https://example.com/schemas/pet.json';
        $client = new FakeHttpClient([
            $url => FakeHttpClient::jsonResponse('{"type":"object","required":["id"]}'),
        ]);

        $cache = [];
        $result = HttpRefLoader::loadDocument($url, $client, $this->factory, $cache);

        $this->assertSame($url, $result['absoluteUri']);
        $this->assertSame(['type' => 'object', 'required' => ['id']], $result['decoded']);
    }

    #[Test]
    public function fetches_and_decodes_yaml_via_url_extension(): void
    {
        $url = 'https://example.com/schemas/pet.yaml';
        $client = new FakeHttpClient([
            $url => FakeHttpClient::yamlResponse("type: object\nrequired:\n  - id\n"),
        ]);

        $cache = [];
        $result = HttpRefLoader::loadDocument($url, $client, $this->factory, $cache);

        $this->assertSame(['type' => 'object', 'required' => ['id']], $result['decoded']);
    }

    #[Test]
    public function falls_back_to_content_type_when_url_has_no_extension(): void
    {
        // Schema Registry endpoints frequently expose JSON via opaque
        // URLs (e.g. `/registry/Pet`) without a file extension. The
        // Content-Type header is the only format cue.
        $url = 'https://registry.example.com/Pet';
        $client = new FakeHttpClient([
            $url => new Response(200, ['Content-Type' => 'application/json'], '{"type":"string"}'),
        ]);

        $cache = [];
        $result = HttpRefLoader::loadDocument($url, $client, $this->factory, $cache);

        $this->assertSame(['type' => 'string'], $result['decoded']);
    }

    #[Test]
    public function falls_back_to_application_problem_json_content_type(): void
    {
        $url = 'https://example.com/schemas/error';
        $client = new FakeHttpClient([
            $url => new Response(200, ['Content-Type' => 'application/problem+json'], '{"type":"object"}'),
        ]);

        $cache = [];
        $result = HttpRefLoader::loadDocument($url, $client, $this->factory, $cache);

        $this->assertSame(['type' => 'object'], $result['decoded']);
    }

    #[Test]
    public function honours_text_yaml_content_type(): void
    {
        $url = 'https://example.com/registry/Schema';
        $client = new FakeHttpClient([
            $url => new Response(200, ['Content-Type' => 'text/yaml; charset=utf-8'], "type: integer\n"),
        ]);

        $cache = [];
        $result = HttpRefLoader::loadDocument($url, $client, $this->factory, $cache);

        $this->assertSame(['type' => 'integer'], $result['decoded']);
    }

    #[Test]
    public function caches_response_and_does_not_re_fetch_on_second_call(): void
    {
        $url = 'https://example.com/pet.json';
        $callCount = 0;
        $client = new FakeHttpClient([
            $url => static function (RequestInterface $req) use (&$callCount): Response {
                $callCount++;

                return new Response(200, ['Content-Type' => 'application/json'], '{"v":1}');
            },
        ]);

        $cache = [];
        HttpRefLoader::loadDocument($url, $client, $this->factory, $cache);
        HttpRefLoader::loadDocument($url, $client, $this->factory, $cache);

        $this->assertSame(1, $callCount);
    }

    #[Test]
    public function throws_remote_ref_fetch_failed_on_404(): void
    {
        $url = 'https://example.com/missing.json';
        $client = new FakeHttpClient([
            $url => new Response(404),
        ]);

        try {
            $cache = [];
            HttpRefLoader::loadDocument($url, $client, $this->factory, $cache);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::RemoteRefFetchFailed, $e->reason);
            $this->assertStringContainsString('404', $e->getMessage());
            $this->assertStringContainsString($url, $e->getMessage());
        }
    }

    #[Test]
    public function throws_remote_ref_fetch_failed_on_5xx(): void
    {
        $url = 'https://example.com/server-error.json';
        $client = new FakeHttpClient([
            $url => new Response(500),
        ]);

        try {
            $cache = [];
            HttpRefLoader::loadDocument($url, $client, $this->factory, $cache);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::RemoteRefFetchFailed, $e->reason);
            $this->assertStringContainsString('500', $e->getMessage());
        }
    }

    #[Test]
    public function throws_remote_ref_fetch_failed_on_network_exception(): void
    {
        $url = 'https://example.com/unreachable.json';
        $client = new FakeHttpClient([
            $url => static function (): Response {
                throw new FakeHttpClientUnexpectedRequest('connection refused');
            },
        ]);

        try {
            $cache = [];
            HttpRefLoader::loadDocument($url, $client, $this->factory, $cache);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::RemoteRefFetchFailed, $e->reason);
            $this->assertStringContainsString('connection refused', $e->getMessage());
            $this->assertNotNull($e->getPrevious());
        }
    }

    #[Test]
    public function throws_unsupported_extension_when_neither_url_nor_content_type_indicates_format(): void
    {
        $url = 'https://example.com/opaque-resource';
        $client = new FakeHttpClient([
            $url => new Response(200, ['Content-Type' => 'text/plain'], 'whatever'),
        ]);

        try {
            $cache = [];
            HttpRefLoader::loadDocument($url, $client, $this->factory, $cache);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::UnsupportedExtension, $e->reason);
            $this->assertStringContainsString($url, $e->getMessage());
        }
    }

    #[Test]
    public function throws_malformed_json_on_invalid_body(): void
    {
        $url = 'https://example.com/bad.json';
        $client = new FakeHttpClient([
            $url => FakeHttpClient::jsonResponse('{ not valid json'),
        ]);

        try {
            $cache = [];
            HttpRefLoader::loadDocument($url, $client, $this->factory, $cache);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::MalformedJson, $e->reason);
            $this->assertNotNull($e->getPrevious());
        }
    }

    #[Test]
    public function throws_malformed_yaml_on_invalid_body(): void
    {
        $url = 'https://example.com/bad.yaml';
        $client = new FakeHttpClient([
            $url => FakeHttpClient::yamlResponse("key: value\n  bad: indent\n"),
        ]);

        try {
            $cache = [];
            HttpRefLoader::loadDocument($url, $client, $this->factory, $cache);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::MalformedYaml, $e->reason);
        }
    }

    #[Test]
    public function throws_non_mapping_root_when_response_decodes_to_scalar(): void
    {
        $url = 'https://example.com/scalar.json';
        $client = new FakeHttpClient([
            $url => FakeHttpClient::jsonResponse('"just a string"'),
        ]);

        try {
            $cache = [];
            HttpRefLoader::loadDocument($url, $client, $this->factory, $cache);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::NonMappingRoot, $e->reason);
        }
    }
}
