<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Fuzz;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Fuzz\ExplorationCases;
use Studio\OpenApiContractTesting\Fuzz\ExploredCase;

use function iterator_to_array;

class ExplorationCasesTest extends TestCase
{
    #[Test]
    public function counts_underlying_cases(): void
    {
        $collection = new ExplorationCases([
            $this->case('a'),
            $this->case('b'),
        ]);

        $this->assertCount(2, $collection);
    }

    #[Test]
    public function iterates_in_insertion_order(): void
    {
        $a = $this->case('a');
        $b = $this->case('b');
        $c = $this->case('c');
        $collection = new ExplorationCases([$a, $b, $c]);

        $this->assertSame([$a, $b, $c], iterator_to_array($collection, preserve_keys: false));
    }

    #[Test]
    public function each_applies_callback_in_order(): void
    {
        $seen = [];
        $a = $this->case('a');
        $b = $this->case('b');
        $collection = new ExplorationCases([$a, $b]);

        $collection->each(static function (ExploredCase $case) use (&$seen): void {
            $seen[] = $case->matchedPath;
        });

        $this->assertSame(['a', 'b'], $seen);
    }

    #[Test]
    public function each_returns_self_for_chainability(): void
    {
        $collection = new ExplorationCases([$this->case('a')]);

        $this->assertSame($collection, $collection->each(static fn(ExploredCase $c): null => null));
    }

    private function case(string $marker): ExploredCase
    {
        return new ExploredCase(
            body: null,
            query: [],
            headers: [],
            pathParams: [],
            method: 'GET',
            matchedPath: $marker,
        );
    }
}
