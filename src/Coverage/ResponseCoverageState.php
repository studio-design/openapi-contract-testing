<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Coverage;

/**
 * Resolved coverage state for a single declared `(status, content-type)`
 * pair on an endpoint — exposed on `CoverageResult.endpoints[].responses[].state`.
 *
 * - {@see self::Validated}: at least one recording reconciled to this pair
 *   AND the validator ran (skipped recordings never promote to Validated).
 * - {@see self::Skipped}: at least one recording reconciled to this pair
 *   but every reconciled recording was skipped (e.g. the status matched
 *   a `skip_response_codes` pattern, or the validator had no schema
 *   engine for the declared content-type).
 * - {@see self::Uncovered}: no recording reconciled to this pair.
 */
enum ResponseCoverageState: string
{
    case Validated = 'validated';
    case Skipped = 'skipped';
    case Uncovered = 'uncovered';
}
