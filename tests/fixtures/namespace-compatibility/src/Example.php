<?php

declare(strict_types=1);

namespace Studio\Gesso;

#[Marker('canonical')]
final class Example implements Contract
{
    use ReusableTrait;
    private static int $calls = 0;

    public static function calls(): int
    {
        return self::$calls;
    }

    public function label(): string
    {
        self::$calls++;

        return 'example';
    }
}
