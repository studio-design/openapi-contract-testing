<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use function implode;

final class OpenApiValidationResult
{
    /**
     * Private so the three factories (success / failure / skipped) are the
     * only way to construct a result. This prevents illegal combinations such
     * as `valid=false, skipped=true` or `valid=true, errors=['x']` — the
     * factories enforce every invariant the type depends on.
     *
     * @param string[] $errors
     */
    private function __construct(
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
     * true so callers that gate on it (e.g. PHPUnit assertions) treat the
     * result as non-failing; isSkipped() distinguishes it from a genuine
     * successful schema match.
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
