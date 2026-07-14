<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

/** @internal */
final readonly class SchemaMutation
{
    public function __construct(
        public mixed $value,
        public string $keyword,
        public string $pointer,
    ) {}
}
