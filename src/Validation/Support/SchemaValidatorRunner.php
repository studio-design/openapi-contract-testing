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
     * Validate `$data` against `$jsonSchema` (both already converted to
     * stdClass via {@see ObjectConverter::convert()}) and return a map of
     * JSON Pointer path → list of human-readable error messages.
     *
     * An empty array means the data validated successfully. The pointer key
     * matches opis's ErrorFormatter output, with `/` indicating the document
     * root.
     *
     * @return array<string, string[]>
     */
    public function validate(mixed $jsonSchema, mixed $data): array
    {
        $result = $this->opisValidator->validate($data, $jsonSchema);

        if ($result->isValid()) {
            return [];
        }

        return $this->errorFormatter->format($result->error());
    }
}
