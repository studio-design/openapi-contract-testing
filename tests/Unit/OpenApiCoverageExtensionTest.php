<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Extension\ParameterCollection;
use Studio\OpenApiContractTesting\InvalidOpenApiSpecException;
use Studio\OpenApiContractTesting\InvalidOpenApiSpecReason;
use Studio\OpenApiContractTesting\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\PHPUnit\OpenApiCoverageExtension;

use function fclose;
use function file_get_contents;
use function fopen;
use function rewind;
use function stream_get_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

class OpenApiCoverageExtensionTest extends TestCase
{
    /** @var null|resource */
    private $stderrBuffer;
    private ?string $githubSummaryTmp = null;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();

        $buffer = fopen('php://memory', 'w+');
        if ($buffer === false) {
            $this->fail('Could not open in-memory buffer for STDERR capture');
        }
        $this->stderrBuffer = $buffer;
        OpenApiCoverageExtension::overrideStderrForTesting($buffer);
    }

    protected function tearDown(): void
    {
        OpenApiCoverageExtension::overrideStderrForTesting(null);
        if ($this->stderrBuffer !== null) {
            fclose($this->stderrBuffer);
            $this->stderrBuffer = null;
        }
        if ($this->githubSummaryTmp !== null) {
            @unlink($this->githubSummaryTmp);
            $this->githubSummaryTmp = null;
        }
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function bootstrap_throws_invalid_spec_exception_when_ref_unresolvable(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => 'refs-unresolvable',
        ]);

        $this->expectException(InvalidOpenApiSpecException::class);
        $this->expectExceptionMessage('Unresolvable $ref');

        $extension->setupExtension(null, $parameters, null);
    }

    #[Test]
    public function bootstrap_writes_warning_but_continues_for_missing_spec_file(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => 'does-not-exist',
        ]);

        $extension->setupExtension(null, $parameters, null);

        $this->assertStringContainsString('WARNING', $this->readStderr());
        $this->assertStringContainsString('does-not-exist', $this->readStderr());
    }

    #[Test]
    public function bootstrap_loads_cleanly_for_valid_spec(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => 'refs-valid',
        ]);

        $extension->setupExtension(null, $parameters, null);

        $this->assertSame('', $this->readStderr());
    }

    #[Test]
    public function bootstrap_appends_fatal_block_to_github_step_summary(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'openapi-summary-');
        if ($tmp === false) {
            $this->fail('Could not create temp file for GITHUB_STEP_SUMMARY');
        }
        $this->githubSummaryTmp = $tmp;

        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => 'refs-unresolvable',
        ]);

        try {
            $extension->setupExtension(null, $parameters, $tmp);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException) {
            // Expected — the FATAL block must still have been written before re-throw.
        }

        $contents = (string) file_get_contents($tmp);
        $this->assertStringContainsString('FATAL OpenAPI spec error', $contents);
        $this->assertStringContainsString('refs-unresolvable', $contents);
        $this->assertStringContainsString('Unresolvable $ref', $contents);
    }

    #[Test]
    public function bootstrap_throws_for_malformed_json_spec(): void
    {
        // Pre-PR catch-all demoted malformed JSON to a stderr WARNING, which
        // is functionally the same silent-pass hole as the $ref case issue
        // #79 set out to close. Pin the fatal treatment so it cannot regress.
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => 'malformed-json',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::MalformedJson, $e->reason);
        }

        $this->assertStringContainsString('FATAL', $this->readStderr());
    }

    #[Test]
    public function bootstrap_throws_when_base_path_not_configured(): void
    {
        // Omitting spec_base_path is a misconfiguration, not a missing file —
        // previously bundled into the warn-and-continue bucket via the broad
        // RuntimeException catch. Treat as fatal so users see their mistake.
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'specs' => 'refs-valid',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::BasePathNotConfigured, $e->reason);
        }
    }

    #[Test]
    public function bootstrap_continues_past_missing_spec_to_load_valid_ones(): void
    {
        // A stale entry in `specs=` should not block the rest of the list
        // from being eager-loaded. Pins the warn-and-continue semantics that
        // differentiate SpecFileNotFoundException from the fatal paths.
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => 'does-not-exist,refs-valid',
        ]);

        $extension->setupExtension(null, $parameters, null);

        $this->assertStringContainsString("WARNING: Skipping spec 'does-not-exist'", $this->readStderr());
        // refs-valid must have loaded cleanly — its content is cached.
        $spec = OpenApiSpecLoader::load('refs-valid');
        $this->assertSame('Refs valid', $spec['info']['title']);
    }

    #[Test]
    public function bootstrap_hard_fails_even_when_earlier_specs_are_valid(): void
    {
        // Order-independence: the loop must reach and trip on the broken spec
        // even if a good spec ran first. A future early-return on "first
        // successful load" refactor would be caught here.
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => 'refs-valid,refs-unresolvable',
        ]);

        $this->expectException(InvalidOpenApiSpecException::class);
        $this->expectExceptionMessage('Unresolvable $ref');

        $extension->setupExtension(null, $parameters, null);
    }

    private function readStderr(): string
    {
        if ($this->stderrBuffer === null) {
            return '';
        }
        rewind($this->stderrBuffer);

        return (string) stream_get_contents($this->stderrBuffer);
    }
}
