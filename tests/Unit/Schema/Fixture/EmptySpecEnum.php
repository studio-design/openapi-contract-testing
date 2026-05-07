<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture;

use Studio\OpenApiContractTesting\Attribute\BoundToOpenApiEnum;

/**
 * Spec carries `enum: []` — the asserter should report all PHP cases as
 * php-only drift rather than treating an empty array as a clean match.
 */
#[BoundToOpenApiEnum('enum-drift/empty-enum.json')]
enum EmptySpecEnum: string
{
    case A = 'a';
    case B = 'b';
}
