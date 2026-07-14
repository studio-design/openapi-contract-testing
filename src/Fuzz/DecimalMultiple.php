<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use const JSON_PRESERVE_ZERO_FRACTION;
use const PHP_INT_MAX;

use function abs;
use function intdiv;
use function is_string;
use function json_encode;
use function ltrim;
use function preg_match;
use function str_repeat;
use function strcmp;
use function strlen;

/** @internal */
final class DecimalMultiple
{
    /**
     * Return the smallest positive integer that is a multiple of the supplied
     * finite JSON number. For example 1.5 (3/2) yields 3, while 0.5 (1/2)
     * yields 1. Values outside the platform integer range return null.
     */
    public static function integerStep(float|int $multipleOf): ?int
    {
        $fraction = self::fraction($multipleOf);

        return $fraction['numerator'] ?? null;
    }

    /**
     * Return the smallest positive value that is an integer multiple of both
     * finite JSON numbers.
     */
    public static function leastCommonMultiple(float|int $left, float|int $right): null|float|int
    {
        $leftFraction = self::fraction($left);
        $rightFraction = self::fraction($right);
        if ($leftFraction === null || $rightFraction === null) {
            return null;
        }

        $numeratorFactor = intdiv(
            $leftFraction['numerator'],
            self::greatestCommonDivisor($leftFraction['numerator'], $rightFraction['numerator']),
        );
        if ($numeratorFactor > intdiv(PHP_INT_MAX, $rightFraction['numerator'])) {
            return null;
        }
        $numerator = $numeratorFactor * $rightFraction['numerator'];
        $denominator = self::greatestCommonDivisor(
            $leftFraction['denominator'],
            $rightFraction['denominator'],
        );

        return $denominator === 1 ? $numerator : $numerator / $denominator;
    }

    /** @return null|array{numerator: int, denominator: int} */
    private static function fraction(float|int $multipleOf): ?array
    {
        if ($multipleOf <= 0) {
            return null;
        }

        $encoded = json_encode($multipleOf, JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($encoded) ||
            preg_match('/^(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/', $encoded, $matches) !== 1) {
            return null;
        }

        $fraction = $matches[2] ?? '';
        $exponent = (int) ($matches[3] ?? 0);
        $digits = ltrim($matches[1] . $fraction, '0');
        if ($digits === '') {
            return null;
        }

        $decimalPlaces = strlen($fraction) - $exponent;
        if ($decimalPlaces < 0) {
            $digits .= str_repeat('0', -$decimalPlaces);
            $decimalPlaces = 0;
        }
        if ($decimalPlaces > 18 || !self::fitsPlatformInteger($digits)) {
            return null;
        }

        $numerator = (int) $digits;
        $denominator = 10 ** $decimalPlaces;
        $divisor = self::greatestCommonDivisor($numerator, $denominator);

        return [
            'numerator' => intdiv($numerator, $divisor),
            'denominator' => intdiv($denominator, $divisor),
        ];
    }

    private static function fitsPlatformInteger(string $digits): bool
    {
        $maximum = (string) PHP_INT_MAX;

        return strlen($digits) < strlen($maximum) ||
            (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) <= 0);
    }

    private static function greatestCommonDivisor(int $left, int $right): int
    {
        $left = abs($left);
        $right = abs($right);
        while ($right !== 0) {
            [$left, $right] = [$right, $left % $right];
        }

        return $left;
    }
}
