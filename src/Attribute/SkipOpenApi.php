<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class SkipOpenApi
{
    public function __construct(
        public readonly string $reason = '',
    ) {}
}
