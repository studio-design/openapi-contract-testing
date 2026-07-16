<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Schema\Fixture;

use Studio\Gesso\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum('../outside.json')]
enum EscapingSpecEnum: string
{
    case Red = 'red';
    case Green = 'green';
    case Blue = 'blue';
}
