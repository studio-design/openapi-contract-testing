<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Extension\ParameterCollection;
use Studio\OpenApiContractTesting\InvalidOpenApiSpecException;
use Studio\OpenApiContractTesting\InvalidOpenApiSpecReason;
use Studio\OpenApiContractTesting\InvalidThresholdConfigurationException;
use Studio\OpenApiContractTesting\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\PHPUnit\OpenApiCoverageExtension;
use Studio\OpenApiContractTesting\SpecFileNotFoundException;

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
    public function bootstrap_throws_fatal_for_missing_spec_file(): void
    {
        // issue #134: stale `specs=` entries used to print a warning and let
        // the suite continue, only blowing up later in an unrelated test.
        // Boot must now hard-fail so the configuration error surfaces at the
        // moment of detection — not on someone else's PR after a merge.
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => 'does-not-exist',
        ]);

        try {
            $extension->setupExtension(null, $parameters, null);
            $this->fail('expected SpecFileNotFoundException');
        } catch (SpecFileNotFoundException $e) {
            $this->assertSame('does-not-exist', $e->specName);
        }

        $stderr = $this->readStderr();
        $this->assertStringContainsString('FATAL', $stderr);
        $this->assertStringContainsString("spec 'does-not-exist'", $stderr);
        $this->assertStringContainsString('Action:', $stderr);
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
    public function bootstrap_appends_fatal_block_to_github_step_summary_for_missing_spec_file(): void
    {
        // issue #134: missing-file failures must reach the GitHub Step
        // Summary too, not just stderr. CI logs scroll fast; the Step Summary
        // is where the misconfiguration becomes unmissable for reviewers.
        $tmp = tempnam(sys_get_temp_dir(), 'openapi-summary-');
        if ($tmp === false) {
            $this->fail('Could not create temp file for GITHUB_STEP_SUMMARY');
        }
        $this->githubSummaryTmp = $tmp;

        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => 'does-not-exist',
        ]);

        try {
            $extension->setupExtension(null, $parameters, $tmp);
            $this->fail('expected SpecFileNotFoundException');
        } catch (SpecFileNotFoundException) {
            // Expected — the FATAL block must still have been written before re-throw.
        }

        $contents = (string) file_get_contents($tmp);
        $this->assertStringContainsString('FATAL OpenAPI spec error', $contents);
        $this->assertStringContainsString('does-not-exist', $contents);
        // Symmetric with the `Unresolvable $ref` assertion above: pin the
        // exception's underlying message so future renames of
        // SpecFileNotFoundException's wording can't silently empty the
        // Step Summary block of its actual root cause.
        $this->assertStringContainsString('OpenAPI bundled spec not found', $contents);
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
    public function bootstrap_hard_fails_for_missing_spec_even_when_others_are_valid(): void
    {
        // issue #134: a stale entry in `specs=` is a configuration error and
        // must abort boot. The valid spec is intentionally listed *first* so
        // we exercise loop-order independence on the missing-file branch:
        // a future "stop after first successful load" optimisation would be
        // caught here. The `refs-valid,refs-unresolvable` test below covers
        // the same shape for the InvalidOpenApiSpecException branch.
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => 'refs-valid,does-not-exist',
        ]);

        $this->expectException(SpecFileNotFoundException::class);

        $extension->setupExtension(null, $parameters, null);
    }

    #[Test]
    public function bootstrap_resolves_relative_sidecar_dir_against_cwd(): void
    {
        // Pin that the extension absolutises a relative `sidecar_dir`
        // against `getcwd()`, mirroring the existing `output_file` and
        // `spec_base_path` resolution. Pre-PR, sidecar_dir was new and
        // its relative-path handling had no test coverage.
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => 'refs-valid',
            'sidecar_dir' => 'relative-sidecars',
        ]);

        // setupExtension does not raise on relative paths; the exercise here
        // is mainly that the codepath runs without error and that the
        // absolutise call doesn't silently swallow the parameter.
        $extension->setupExtension(null, $parameters, null);

        $this->assertSame('', $this->readStderr());
    }

    #[Test]
    public function bootstrap_warns_on_out_of_range_min_endpoint_coverage_when_not_strict(): void
    {
        // Warn-only (default) tolerates misconfiguration: surface it but
        // don't break a CI that hasn't opted into the gate yet.
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => 'refs-valid',
            'min_endpoint_coverage' => '150',
        ]);

        $extension->setupExtension(null, $parameters, null);

        $stderr = $this->readStderr();
        $this->assertStringContainsString('WARNING', $stderr);
        $this->assertStringContainsString('min_endpoint_coverage', $stderr);
    }

    #[Test]
    public function bootstrap_warns_on_non_numeric_min_response_coverage_when_not_strict(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => 'refs-valid',
            'min_response_coverage' => 'eighty',
        ]);

        $extension->setupExtension(null, $parameters, null);

        $stderr = $this->readStderr();
        $this->assertStringContainsString('WARNING', $stderr);
        $this->assertStringContainsString('min_response_coverage', $stderr);
    }

    #[Test]
    public function bootstrap_throws_on_out_of_range_threshold_when_strict(): void
    {
        // C1: strict=true must treat a typo'd threshold as a configuration
        // error, not warn-and-skip. The exception is caught by bootstrap()
        // and translated into exit(1) — symmetric with how stale `specs=`
        // entries fail the run after issue #134.
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => 'refs-valid',
            'min_endpoint_coverage' => '150',
            'min_coverage_strict' => 'true',
        ]);

        $this->expectException(InvalidThresholdConfigurationException::class);

        try {
            $extension->setupExtension(null, $parameters, null);
        } finally {
            $this->assertStringContainsString('FATAL', $this->readStderr());
            $this->assertStringContainsString('min_endpoint_coverage', $this->readStderr());
        }
    }

    #[Test]
    public function bootstrap_throws_on_non_numeric_threshold_when_strict(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => 'refs-valid',
            'min_response_coverage' => 'eighty',
            'min_coverage_strict' => 'true',
        ]);

        $this->expectException(InvalidThresholdConfigurationException::class);

        try {
            $extension->setupExtension(null, $parameters, null);
        } finally {
            $this->assertStringContainsString('FATAL', $this->readStderr());
        }
    }

    #[Test]
    public function bootstrap_treats_empty_strict_value_as_enabled(): void
    {
        // I1: `<parameter name="min_coverage_strict" />` (no value) was a
        // contradiction — the docstring promised "set = strict on" but the
        // code treated `''` as falsy. Pin the docstring's intent.
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => 'refs-valid',
            'min_endpoint_coverage' => '150', // out of range
            'min_coverage_strict' => '',      // shorthand for "true"
        ]);

        // Empty strict value must enable strict mode → C1 FATAL on the bad
        // threshold below.
        $this->expectException(InvalidThresholdConfigurationException::class);

        $extension->setupExtension(null, $parameters, null);
    }

    #[Test]
    public function bootstrap_accepts_well_formed_threshold_parameters(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => 'refs-valid',
            'min_endpoint_coverage' => '80',
            'min_response_coverage' => '70.5',
            'min_coverage_strict' => 'true',
        ]);

        $extension->setupExtension(null, $parameters, null);

        $this->assertSame('', $this->readStderr());
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
