<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture;

use Studio\OpenApiContractTesting\Attribute\BoundToOpenApiEnum;

/**
 * Spec carries `[200, 201, "201"]` — the string `"201"` must surface as
 * spec-only drift via strict comparison, not silently match the int 201.
 */
#[BoundToOpenApiEnum('enum-drift/integer-backed-drift.json')]
enum IntegerBackedDriftEnum: int
{
    case Ok = 200;
    case Created = 201;
}
