<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use RuntimeException;
use Throwable;

/**
 * Thrown when a registered spec name has no matching file on disk. Distinct
 * from `InvalidOpenApiSpecException` so the coverage extension can continue
 * past a stale `specs=` entry with a warning instead of aborting the run.
 */
final class SpecFileNotFoundException extends RuntimeException
{
    public function __construct(
        public readonly string $specName,
        public readonly string $basePath,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
