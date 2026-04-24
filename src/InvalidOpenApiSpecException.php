<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use RuntimeException;
use Throwable;

/**
 * Thrown when an OpenAPI spec is syntactically parseable as a file but
 * semantically broken in a way that makes contract validation impossible.
 * The exhaustive list of failure categories lives on
 * `InvalidOpenApiSpecReason`; consumers should branch on `$reason` rather
 * than pattern-matching `$message`.
 *
 * Separate from `SpecFileNotFoundException` so the PHPUnit coverage
 * extension can hard-fail the run on broken specs while continuing to
 * warn for a missing spec file.
 */
final class InvalidOpenApiSpecException extends RuntimeException
{
    public function __construct(
        public readonly InvalidOpenApiSpecReason $reason,
        string $message,
        public readonly ?string $ref = null,
        public readonly ?string $specName = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Return a copy of this exception with `$specName` attached. Used by
     * `OpenApiSpecLoader` to annotate resolver-originated throws (the
     * resolver is stateless and does not know the spec name).
     */
    public function withSpecName(string $specName): self
    {
        return new self(
            $this->reason,
            $this->getMessage(),
            $this->ref,
            $specName,
            $this,
        );
    }
}
