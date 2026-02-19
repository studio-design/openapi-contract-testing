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
    ) {}

    public static function success(?string $matchedPath = null): self
    {
        return new self(true, [], $matchedPath);
    }

    /** @param string[] $errors */
    public static function failure(array $errors): self
    {
        return new self(false, $errors);
    }

    public function isValid(): bool
    {
        return $this->valid;
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
}
