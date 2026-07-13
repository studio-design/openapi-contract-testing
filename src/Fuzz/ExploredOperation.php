<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Fuzz;

use function implode;
use function sprintf;
use function var_export;

/**
 * Metadata for one operation selected by a whole-spec exploration.
 */
final readonly class ExploredOperation
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public string $specName,
        public string $method,
        public string $path,
        public ?string $operationId,
        public array $tags,
        public bool $deprecated,
        public int $seed,
    ) {}

    /**
     * Return a minimal expression that regenerates the exact input case.
     */
    public function replaySnippet(int $caseIndex): string
    {
        return sprintf(
            'OpenApiEndpointExplorer::explore(%s, %s, %s, cases: %d, seed: %d)->cases[%d]',
            var_export($this->specName, true),
            var_export($this->method, true),
            var_export($this->path, true),
            $caseIndex + 1,
            $this->seed,
            $caseIndex,
        );
    }

    /**
     * Return a minimal expression that regenerates the exact negative input case.
     *
     * @param list<int> $expectedStatusClasses
     */
    public function replayInvalidSnippet(int $caseIndex, array $expectedStatusClasses): string
    {
        return sprintf(
            'OpenApiEndpointExplorer::exploreInvalid(%s, %s, %s, expectedStatusClasses: [%s], cases: %d, seed: %d)->cases[%d]',
            var_export($this->specName, true),
            var_export($this->method, true),
            var_export($this->path, true),
            implode(', ', $expectedStatusClasses),
            $caseIndex + 1,
            $this->seed,
            $caseIndex,
        );
    }

    /**
     * Key shape used by OpenApiCoverageTracker diagnostic rows.
     */
    public function coverageKey(): string
    {
        return $this->method . ' ' . $this->path;
    }
}
