<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture;

use Studio\OpenApiContractTesting\Attribute\BoundToOpenApiEnum;

/**
 * Pure (non-backed) enum carrying the binding attribute — the asserter
 * must reject this loudly rather than silently produce a useless diff.
 */
#[BoundToOpenApiEnum('enum-drift/matching.json')]
enum PureEnum
{
    case Foo;
    case Bar;
}
