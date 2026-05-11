<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Coverage;

use RuntimeException;
use Studio\OpenApiContractTesting\PHPUnit\OpenApiCoverageExtension;
use Throwable;

/**
 * Thrown by {@see OpenApiCoverageExtension} when an output-file path parameter
 * (currently `junit_output`; reusable for additional formats added under #116)
 * is set but invalid — empty/whitespace value, or a parent directory that
 * does not exist / is not writable.
 *
 * Distinct from {@see InvalidThresholdConfigurationException} because the
 * failure mode is filesystem misconfiguration, not a threshold typo. Distinct
 * from a generic SPL `InvalidArgumentException` so `bootstrap()` can catch
 * exactly this case without swallowing unrelated argument errors raised
 * deeper in the call graph (e.g. from spec loading or path matching).
 */
final class InvalidCoverageOutputPathException extends RuntimeException
{
    public function __construct(
        public readonly string $parameterName,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
