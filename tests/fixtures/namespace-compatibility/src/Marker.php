<?php

declare(strict_types=1);

namespace Studio\Gesso;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Marker
{
    public function __construct(public string $value) {}
}
