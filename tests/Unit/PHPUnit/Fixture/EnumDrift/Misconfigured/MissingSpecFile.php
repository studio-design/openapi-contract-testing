<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\PHPUnit\Fixture\EnumDrift\Misconfigured;

use Studio\OpenApiContractTesting\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum('enum-drift/_shared/components/schemas/enums/DoesNotExist.json')]
enum MissingSpecFile: string
{
    case Anything = 'anything';
}
