<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

final readonly class ExplorationSkip
{
    public function __construct(
        public ExploredOperation $operation,
        public string $reason,
    ) {}
}
