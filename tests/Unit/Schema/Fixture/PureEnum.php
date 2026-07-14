<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Schema\Fixture;

use Studio\Gesso\Attribute\BoundToOpenApiEnum;

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
