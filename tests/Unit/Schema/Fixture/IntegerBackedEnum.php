<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture;

use Studio\OpenApiContractTesting\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum('enum-drift/integer-backed.json')]
enum IntegerBackedEnum: int
{
    case Ok = 200;
    case Created = 201;
    case NoContent = 204;
}
