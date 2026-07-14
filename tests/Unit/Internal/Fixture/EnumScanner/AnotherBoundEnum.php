<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Internal\Fixture\EnumScanner;

use Studio\Gesso\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum('fixture/another.json')]
enum AnotherBoundEnum: int
{
    case One = 1;
    case Two = 2;
}
