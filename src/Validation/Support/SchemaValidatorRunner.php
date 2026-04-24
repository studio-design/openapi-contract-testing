<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Support;

use const PHP_INT_MAX;

use InvalidArgumentException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

use function sprintf;

final class SchemaValidatorRunner
{
    private readonly Validator $opisValidator;
    private readonly ErrorFormatter $errorFormatter;

    public function __construct(int $maxErrors)
    {
        if ($maxErrors < 0) {
            throw new InvalidArgumentException(
                sprintf('maxErrors must be 0 (unlimited) or a positive integer, got %d.', $maxErrors),
            );
        }

        $resolvedMaxErrors = $maxErrors === 0 ? PHP_INT_MAX : $maxErrors;
        $this->opisValidator = new Validator(
            max_errors: $resolvedMaxErrors,
            stop_at_first_error: $resolvedMaxErrors === 1,
        );
        $this->errorFormatter = new ErrorFormatter();
    }

    /**
     * Validate `$data` against `$jsonSchema` (typically both converted via
     * {@see ObjectConverter::convert()}, although opis also accepts `true` /
     * `false` top-level schemas and raw scalars) and return a map of JSON
     * Pointer path → list of human-readable error messages.
     *
     * An empty array means the data validated successfully. The pointer key
     * matches opis's ErrorFormatter output, with `/` indicating the document
     * root. Success is determined by `ValidationResult::isValid()` rather
     * than by the formatter output shape, so a future opis change that
     * returned `[]` for a suppressed/filtered error would still be reported
     * as a failure (no silent pass).
     *
     * @return array<string, string[]>
     */
    public function validate(mixed $jsonSchema, mixed $data): array
    {
        $result = $this->opisValidator->validate($data, $jsonSchema);

        if ($result->isValid()) {
            return [];
        }

        $error = $result->error();
        if ($error === null) {
            // Defensive: ValidationResult::isValid() is defined as
            // `$this->error === null`, so this branch is unreachable today.
            // Return a synthetic entry rather than letting a null slip to
            // ErrorFormatter::format() and producing a TypeError, so the
            // validator still surfaces *something* if the opis invariant
            // ever changes.
            return ['/' => ['Schema validation failed but opis reported no error detail.']];
        }

        return $this->errorFormatter->format($error);
    }
}
