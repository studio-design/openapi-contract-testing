<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

/**
 * Resolved coverage state for a single (method, path) operation —
 * exposed on `CoverageResult.endpoints[].state`.
 *
 * - {@see self::AllCovered}: every declared `(status, content-type)` pair
 *   was validated.
 * - {@see self::Partial}: at least one pair validated, at least one not.
 *   Skipped pairs count as not-validated, so `2 validated + 1 skipped`
 *   is `Partial`.
 * - {@see self::Uncovered}: no pairs validated, no pairs skipped, and the
 *   request hook never fired.
 * - {@see self::RequestOnly}: the spec declares no responses for the
 *   operation, OR every response observation reconciled only to
 *   `unexpectedObservations`. Test traffic touched the endpoint, but
 *   nothing reconciled to a declared response definition.
 */
enum EndpointCoverageState: string
{
    case AllCovered = 'all-covered';
    case Partial = 'partial';
    case Uncovered = 'uncovered';
    case RequestOnly = 'request-only';
}
