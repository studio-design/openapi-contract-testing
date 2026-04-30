<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\PHPUnit\ConsoleOutput;

use function putenv;

class ConsoleOutputTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clear the environment variable before each test
        putenv('OPENAPI_CONSOLE_OUTPUT');
    }

    protected function tearDown(): void
    {
        putenv('OPENAPI_CONSOLE_OUTPUT');

        parent::tearDown();
    }

    #[Test]
    public function resolve_returns_default_when_parameter_is_null(): void
    {
        $this->assertSame(ConsoleOutput::DEFAULT, ConsoleOutput::resolve(null));
    }

    #[Test]
    public function resolve_returns_default_when_parameter_is_empty_string(): void
    {
        $this->assertSame(ConsoleOutput::DEFAULT, ConsoleOutput::resolve(''));
    }

    #[Test]
    public function resolve_returns_default_when_parameter_is_whitespace(): void
    {
        $this->assertSame(ConsoleOutput::DEFAULT, ConsoleOutput::resolve('  '));
    }

    #[Test]
    public function resolve_returns_all_from_parameter(): void
    {
        $this->assertSame(ConsoleOutput::ALL, ConsoleOutput::resolve('all'));
    }

    #[Test]
    public function resolve_returns_uncovered_only_from_parameter(): void
    {
        $this->assertSame(ConsoleOutput::UNCOVERED_ONLY, ConsoleOutput::resolve('uncovered_only'));
    }

    #[Test]
    public function resolve_returns_active_only_from_parameter(): void
    {
        $this->assertSame(ConsoleOutput::ACTIVE_ONLY, ConsoleOutput::resolve('active_only'));
    }

    #[Test]
    public function resolve_returns_default_from_parameter(): void
    {
        $this->assertSame(ConsoleOutput::DEFAULT, ConsoleOutput::resolve('default'));
    }

    #[Test]
    public function resolve_is_case_insensitive_for_parameter(): void
    {
        $this->assertSame(ConsoleOutput::ALL, ConsoleOutput::resolve('ALL'));
        $this->assertSame(ConsoleOutput::ALL, ConsoleOutput::resolve('All'));
        $this->assertSame(ConsoleOutput::UNCOVERED_ONLY, ConsoleOutput::resolve('UNCOVERED_ONLY'));
        $this->assertSame(ConsoleOutput::UNCOVERED_ONLY, ConsoleOutput::resolve('Uncovered_Only'));
        $this->assertSame(ConsoleOutput::ACTIVE_ONLY, ConsoleOutput::resolve('ACTIVE_ONLY'));
        $this->assertSame(ConsoleOutput::ACTIVE_ONLY, ConsoleOutput::resolve('Active_Only'));
    }

    #[Test]
    public function resolve_trims_whitespace_from_parameter(): void
    {
        $this->assertSame(ConsoleOutput::ALL, ConsoleOutput::resolve('  all  '));
    }

    #[Test]
    public function resolve_returns_default_for_invalid_parameter(): void
    {
        $this->assertSame(ConsoleOutput::DEFAULT, ConsoleOutput::resolve('invalid'));
        $this->assertSame(ConsoleOutput::DEFAULT, ConsoleOutput::resolve('covered_only'));
    }

    #[Test]
    public function resolve_env_overrides_parameter(): void
    {
        putenv('OPENAPI_CONSOLE_OUTPUT=uncovered_only');

        $this->assertSame(ConsoleOutput::UNCOVERED_ONLY, ConsoleOutput::resolve('all'));
    }

    #[Test]
    public function resolve_env_is_case_insensitive(): void
    {
        putenv('OPENAPI_CONSOLE_OUTPUT=ALL');

        $this->assertSame(ConsoleOutput::ALL, ConsoleOutput::resolve(null));
    }

    #[Test]
    public function resolve_env_trims_whitespace(): void
    {
        putenv('OPENAPI_CONSOLE_OUTPUT=  all  ');

        $this->assertSame(ConsoleOutput::ALL, ConsoleOutput::resolve(null));
    }

    #[Test]
    public function resolve_invalid_env_falls_back_to_default(): void
    {
        putenv('OPENAPI_CONSOLE_OUTPUT=invalid');

        $this->assertSame(ConsoleOutput::DEFAULT, ConsoleOutput::resolve('all'));
    }

    #[Test]
    public function resolve_empty_env_uses_parameter(): void
    {
        putenv('OPENAPI_CONSOLE_OUTPUT=');

        $this->assertSame(ConsoleOutput::ALL, ConsoleOutput::resolve('all'));
    }

    #[Test]
    public function resolve_whitespace_only_env_uses_parameter(): void
    {
        putenv('OPENAPI_CONSOLE_OUTPUT=  ');

        $this->assertSame(ConsoleOutput::ALL, ConsoleOutput::resolve('all'));
    }
}
