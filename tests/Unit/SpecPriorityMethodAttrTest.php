<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Laravel\ValidatesOpenApiSchema;
use Studio\OpenApiContractTesting\OpenApiSpec;

// Load namespace-level config() mock before the trait resolves the function call.
require_once __DIR__ . '/../Helpers/LaravelConfigMock.php';

/**
 * Priority row 1 of 4: method-level #[OpenApiSpec] wins over every lower layer.
 *
 * All four priority layers are populated with distinct `from-<layer>` markers;
 * the assertion proves the method attribute is chosen. Pairs with
 * SpecPriorityClassAttrTest, SpecPriorityOverrideTest, and SpecPriorityConfigTest
 * — together they cover the table in issue #51.
 */
#[OpenApiSpec('from-class-attr')]
class SpecPriorityMethodAttrTest extends TestCase
{
    use ValidatesOpenApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__openapi_testing_config'] = [
            'openapi-contract-testing.default_spec' => 'from-config',
        ];
    }

    protected function tearDown(): void
    {
        self::resetValidatorCache();
        unset($GLOBALS['__openapi_testing_config']);
        parent::tearDown();
    }

    #[Test]
    #[OpenApiSpec('from-method-attr')]
    public function method_attr_wins_over_class_attr_override_and_config(): void
    {
        $this->assertSame('from-method-attr', $this->resolveOpenApiSpec());
    }

    // Layer 3 override: returning a distinct marker proves the method attribute
    // (layer 1) is still chosen over it. If the resolver regressed to pick this
    // up, the assertion above would see 'from-override' instead.
    protected function openApiSpec(): string
    {
        return 'from-override';
    }
}
