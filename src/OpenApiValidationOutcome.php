<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

/**
 * Tri-state outcome of an OpenAPI validation run.
 *
 * Replaces the prior (valid:bool, skipped:bool) pair on {@see OpenApiValidationResult}
 * so the legal state space is expressed as 3 cases rather than 3-of-4 bool combinations.
 * Callers can `match ($result->outcome())` with PHPStan exhaustiveness checking instead
 * of relying on the implicit `isValid() === success OR skipped` convention.
 */
enum OpenApiValidationOutcome: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Skipped = 'skipped';
}
