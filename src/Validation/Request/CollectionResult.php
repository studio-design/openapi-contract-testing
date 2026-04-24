<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Request;

/**
 * Outcome of merging path-level and operation-level `parameters` entries.
 *
 * `$parameters` contains the deduplicated list of well-formed parameter
 * definitions that downstream per-transport validators should iterate.
 * `$specErrors` contains human-readable errors about malformed entries that
 * were dropped during collection — these are surfaced to callers alongside
 * any per-request validation errors so a single test run reports every
 * contract drift at once.
 */
final readonly class CollectionResult
{
    /**
     * @param list<array<string, mixed>> $parameters
     * @param string[] $specErrors
     */
    public function __construct(
        public array $parameters,
        public array $specErrors,
    ) {}
}
