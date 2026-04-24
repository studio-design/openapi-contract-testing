<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use InvalidArgumentException;

use function implode;

final class OpenApiValidationResult
{
    /**
     * Private so the three factories (success / failure / skipped) are the
     * only way to construct a result. The outcome enum narrows the legal
     * state space to exactly those three cases — errors are only attached
     * to Failure, and skipReason is only attached to Skipped.
     *
     * @param string[] $errors
     */
    private function __construct(
        private readonly OpenApiValidationOutcome $outcome,
        private readonly array $errors = [],
        private readonly ?string $matchedPath = null,
        private readonly ?string $skipReason = null,
    ) {}

    public static function success(?string $matchedPath = null): self
    {
        return new self(OpenApiValidationOutcome::Success, [], $matchedPath);
    }

    /**
     * Reject `failure([])` so a Failure always carries at least one error
     * message. Without this guard, `errorMessage()` would return an empty
     * string and the Failure would surface as a silent assertion failure.
     * The `non-empty-array` bound lifts that invariant into the signature so
     * PHPStan flags empty-literal callers at analyse time; the runtime guard
     * remains as defense-in-depth for consumers without static analysis.
     *
     * @param non-empty-array<string> $errors
     *
     * @throws InvalidArgumentException when $errors is empty
     */
    public static function failure(array $errors, ?string $matchedPath = null): self
    {
        // @phpstan-ignore-next-line identical.alwaysFalse — PHPDoc bound is not enforced at runtime; keep guard for consumers without static analysis
        if ($errors === []) {
            throw new InvalidArgumentException(
                'OpenApiValidationResult::failure() requires at least one error message.',
            );
        }

        return new self(OpenApiValidationOutcome::Failure, $errors, $matchedPath);
    }

    /**
     * Represents a response whose body was intentionally not validated (e.g. a
     * 5xx production error that the spec does not document). isValid() stays
     * true so callers that gate on it (e.g. PHPUnit assertions) treat the
     * result as non-failing; isSkipped() / outcome() distinguish it from a
     * genuine successful schema match.
     */
    public static function skipped(?string $matchedPath = null, ?string $reason = null): self
    {
        return new self(OpenApiValidationOutcome::Skipped, [], $matchedPath, $reason);
    }

    public function outcome(): OpenApiValidationOutcome
    {
        return $this->outcome;
    }

    public function isValid(): bool
    {
        return match ($this->outcome) {
            OpenApiValidationOutcome::Success, OpenApiValidationOutcome::Skipped => true,
            OpenApiValidationOutcome::Failure => false,
        };
    }

    public function isSkipped(): bool
    {
        return $this->outcome === OpenApiValidationOutcome::Skipped;
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
