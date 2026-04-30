<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Fuzz;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

use function count;

/**
 * Iterable collection of {@see ExploredCase} produced by
 * {@see OpenApiEndpointExplorer}. The fluent `each()` helper matches the
 * dogfooded API in issue #136.
 *
 * @implements IteratorAggregate<int, ExploredCase>
 */
final readonly class ExplorationCases implements Countable, IteratorAggregate
{
    /**
     * @param list<ExploredCase> $cases
     */
    public function __construct(public array $cases) {}

    public function count(): int
    {
        return count($this->cases);
    }

    /**
     * Apply $callback to each case in order. Returns $this so chains can be
     * composed if a future API needs it; the issue's sketch uses the result
     * as a void terminator.
     *
     * @param callable(ExploredCase): void $callback
     */
    public function each(callable $callback): self
    {
        foreach ($this->cases as $case) {
            $callback($case);
        }

        return $this;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->cases);
    }
}
