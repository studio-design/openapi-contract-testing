<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

enum ExplorationCaseKind: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
}
