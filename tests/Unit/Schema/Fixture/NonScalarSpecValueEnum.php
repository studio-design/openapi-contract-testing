<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture;

use Studio\OpenApiContractTesting\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum('enum-drift/non-scalar-value.json')]
enum NonScalarSpecValueEnum: string
{
    case Red = 'red';
    case Blue = 'blue';
}
