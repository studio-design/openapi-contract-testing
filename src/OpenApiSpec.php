<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class OpenApiSpec
{
    public function __construct(
        public readonly string $name,
    ) {}
}
