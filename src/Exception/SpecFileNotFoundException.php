<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Exception;

use RuntimeException;
use Throwable;

/**
 * Thrown when a registered spec name has no matching file on disk. Distinct
 * from `InvalidOpenApiSpecException` so downstream `CoverageReportSubscriber`
 * and `CoverageMergeCommand` can defensively warn-and-continue when a spec
 * file is unlinked mid-run or a CLI invocation references a sidecar whose
 * spec is no longer on disk. The PHPUnit boot path (issue #134) treats this
 * as fatal: a missing bundled spec at startup is a configuration error and
 * must surface immediately, not as a runtime stack trace inside an unrelated
 * test much later in the suite.
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
