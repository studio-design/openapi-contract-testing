<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Fuzz;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

use function count;

/**
 * Iterable collection of {@see ExploredCase} produced by
 * {@see OpenApiEndpointExplorer}. The fluent `each()` helper matches the
 * dogfooded API in issue #136; callers who prefer plain iteration can also
 * use `foreach ($cases as $case)` thanks to {@see IteratorAggregate}.
 *
 * @implements IteratorAggregate<int, ExploredCase>
 */
final readonly class ExplorationCases implements Countable, IteratorAggregate
{
    /**
     * @param list<ExploredCase> $cases
     *
     * @throws InvalidArgumentException when $cases is empty — the upstream
     *                                  explorer rejects `cases < 1`, so the only way to reach an empty
     *                                  collection is by directly constructing one. Failing fast here
     *                                  prevents `->each(...)` no-ops from passing tests that asserted nothing.
     */
    public function __construct(public array $cases)
    {
        if ($cases === []) {
            throw new InvalidArgumentException(
                'ExplorationCases must contain at least one ExploredCase. '
                . 'An empty collection would let `->each()` callbacks silently never run, '
                . 'producing tests that assert nothing.',
            );
        }
    }

    public function count(): int
    {
        return count($this->cases);
    }

    /**
     * Apply $callback to each case in iteration order. Returns $this so
     * the issue #136 sketched fluent-chain shape stays valid; callers that
     * prefer plain iteration can use `foreach` directly.
     *
     * @param callable(ExploredCase): mixed $callback
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
