<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Internal\Fixture\EnumScanner;

use Studio\OpenApiContractTesting\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum('fixture/clean.json')]
enum CleanBoundEnum: string
{
    case Alpha = 'alpha';
    case Beta = 'beta';
}
