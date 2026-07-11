<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Fuzz;

enum ExplorationCaseKind: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
}
