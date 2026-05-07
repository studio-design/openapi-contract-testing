<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Internal\Fixture\EnumScanner;

enum UnattributedEnum: string
{
    case Foo = 'foo';
    case Bar = 'bar';
}
