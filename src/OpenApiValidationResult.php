<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use function implode;

final class OpenApiValidationResult
{
    /**
     * @param string[] $errors
     */
    public function __construct(
        private readonly bool $valid,
        private readonly array $errors = [],
        private readonly ?string $matchedPath = null,
        private readonly bool $skipped = false,
        private readonly ?string $skipReason = null,
    ) {}

    public static function success(?string $matchedPath = null): self
    {
        return new self(true, [], $matchedPath);
    }

    /** @param string[] $errors */
    public static function failure(array $errors, ?string $matchedPath = null): self
    {
        return new self(false, $errors, $matchedPath);
    }

    /**
     * Represents a response whose body was intentionally not validated (e.g. a
     * 5xx production error that the spec does not document). isValid() stays
     * true so the assertion does not fail the test; isSkipped() distinguishes
     * the case from a genuine successful match for callers that care.
     */
    public static function skipped(?string $matchedPath = null, ?string $reason = null): self
    {
        return new self(true, [], $matchedPath, true, $reason);
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function isSkipped(): bool
    {
        return $this->skipped;
    }

    /** @return string[] */
    public function errors(): array
    {
        return $this->errors;
    }

    public function errorMessage(): string
    {
        return implode("\n", $this->errors);
    }

    public function matchedPath(): ?string
    {
        return $this->matchedPath;
    }

    public function skipReason(): ?string
    {
        return $this->skipReason;
    }
}
