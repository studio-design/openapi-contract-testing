<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use PHPUnit\Event\Subscriber;
use PHPUnit\Event\Tracer\Tracer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use Studio\OpenApiContractTesting\InvalidOpenApiSpecException;
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

        $extension->setupExtension($this->stubFacade(), $parameters, null);
    }

    #[Test]
    public function bootstrap_writes_warning_but_continues_for_missing_spec_file(): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray([
            'spec_base_path' => __DIR__ . '/../fixtures/specs',
            'specs' => 'does-not-exist',
        ]);

        $extension->setupExtension($this->stubFacade(), $parameters, null);

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

        $extension->setupExtension($this->stubFacade(), $parameters, null);

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
            $extension->setupExtension($this->stubFacade(), $parameters, $tmp);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException) {
            // Expected — the FATAL block must still have been written before re-throw.
        }

        $contents = (string) file_get_contents($tmp);
        $this->assertStringContainsString('FATAL OpenAPI spec error', $contents);
        $this->assertStringContainsString('refs-unresolvable', $contents);
        $this->assertStringContainsString('Unresolvable $ref', $contents);
    }

    private function readStderr(): string
    {
        if ($this->stderrBuffer === null) {
            return '';
        }
        rewind($this->stderrBuffer);

        return (string) stream_get_contents($this->stderrBuffer);
    }

    private function stubFacade(): Facade
    {
        return new class implements Facade {
            public function registerSubscribers(Subscriber ...$subscribers): void {}

            public function registerSubscriber(Subscriber $subscriber): void {}

            public function registerTracer(Tracer $tracer): void {}

            public function replaceOutput(): void {}

            public function replaceProgressOutput(): void {}

            public function replaceResultOutput(): void {}

            public function requireCodeCoverageCollection(): void {}
        };
    }
}
