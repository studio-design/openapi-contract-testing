<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\PHPUnit\Fixture\EnumDrift\Misconfigured;

use Studio\Gesso\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum('enum-drift/_shared/components/schemas/enums/DoesNotExist.json')]
enum MissingSpecFile: string
{
    case Anything = 'anything';
}
