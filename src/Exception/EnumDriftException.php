<?php

declare(strict_types=1);

namespace Studio\Gesso\Exception;

use InvalidArgumentException;
use RuntimeException;
use Studio\Gesso\Schema\EnumDriftReport;

/**
 * Thrown when one or more `(PHP enum, OpenAPI enum file)` pairs disagree on
 * their value set. Distinct from `EnumBindingException`, which signals that
 * the comparison could not even be performed (missing attribute, unreadable
 * file, etc.).
 *
 * The full report list is exposed so PHPUnit assertions, CI summary
 * builders, and other consumers can render or count drift programmatically
 * without re-parsing the message.
 */
final class EnumDriftException extends RuntimeException
{
    /**
     * @param list<EnumDriftReport> $reports must be non-empty and every
     *                                       entry must satisfy
     *                                       {@see EnumDriftReport::hasDrift()} — the
     *                                       exception means "drift was detected", so
     *                                       carrying clean or empty reports would
     *                                       contradict the type
     *
     * @throws InvalidArgumentException when the invariant is violated
     */
    public function __construct(
        public readonly array $reports,
        string $message,
    ) {
        if ($reports === []) {
            throw new InvalidArgumentException(
                'EnumDriftException requires at least one EnumDriftReport — empty reports contradict the "drift detected" semantic.',
            );
        }
        foreach ($reports as $report) {
            if (!$report->hasDrift()) {
                throw new InvalidArgumentException(
                    'EnumDriftException reports must all satisfy hasDrift(). Filter clean reports before constructing.',
                );
            }
        }

        parent::__construct($message);
    }
}
