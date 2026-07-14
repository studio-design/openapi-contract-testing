<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use InvalidArgumentException;

use function array_keys;
use function is_array;

/**
 * Deterministically removes payload members while preserving a caller-defined
 * failure classification (for example `status:500` or an exception class).
 */
final class FailureReducer
{
    /** @param callable(ExploredCase): ?string $classify */
    public static function reduce(ExploredCase $case, callable $classify): ExploredCase
    {
        $classification = $classify($case);
        if ($classification === null || $classification === '') {
            throw new InvalidArgumentException('FailureReducer requires the original case to have a failure classification.');
        }

        if (!is_array($case->body)) {
            return $case;
        }

        $body = $case->body;
        foreach (array_keys($body) as $key) {
            $candidate = $body;
            unset($candidate[$key]);
            $candidateCase = $case->withBody($candidate);
            if ($classify($candidateCase) === $classification) {
                $body = $candidate;
            }
        }

        return $case->withBody($body);
    }
}
