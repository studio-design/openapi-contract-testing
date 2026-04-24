<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

/**
 * Tri-state outcome of an OpenAPI validation run, exposed via
 * {@see OpenApiValidationResult::outcome()}. Callers can `match` over
 * this enum and rely on PHPStan exhaustiveness to ensure every case is
 * handled, rather than deriving intent from `isValid()` / `isSkipped()`.
 */
enum OpenApiValidationOutcome: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Skipped = 'skipped';
}
