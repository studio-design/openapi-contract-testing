<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Schema\Fixture;

use Studio\Gesso\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum('enum-drift/no-enum-key.json')]
enum NoEnumKeySpecEnum: string
{
    case A = 'a';
}
