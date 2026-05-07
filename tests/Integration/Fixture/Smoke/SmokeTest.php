<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Integration\Fixture\Smoke;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Tests\Integration\OpenApiCoverageExtensionEnumDriftBootstrapTest;

/**
 * One-trivial-assertion fixture used by
 * {@see OpenApiCoverageExtensionEnumDriftBootstrapTest}
 * so subprocess runs that should exit zero have at least one test to run —
 * PHPUnit returns a non-zero exit when no tests match.
 */
final class SmokeTest extends TestCase
{
    #[Test]
    public function smoke_passes(): void
    {
        $this->expectNotToPerformAssertions();
    }
}
