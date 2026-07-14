<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use RuntimeException;

/** @internal */
final class FuzzGenerationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $caseIndex,
    ) {
        parent::__construct($message);
    }
}
