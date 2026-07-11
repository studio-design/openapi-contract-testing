<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Fuzz;

use Opis\JsonSchema\Validator;
use RuntimeException;

use function json_decode;
use function json_encode;
use function sprintf;

/** @internal */
final class SchemaValueValidator
{
    /** @param array<string, mixed> $schema */
    public static function isValid(mixed $value, array $schema): bool
    {
        $validator = new Validator();
        $instance = json_decode((string) json_encode($value));
        $jsonSchema = json_decode((string) json_encode($schema));

        return $validator->validate($instance, $jsonSchema)->isValid();
    }

    /** @param array<string, mixed> $schema */
    public static function assertValid(mixed $value, array $schema, int $iteration): void
    {
        if (self::isValid($value, $schema)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Internal fuzz generator defect: valid case %d does not satisfy its converted JSON Schema.',
            $iteration,
        ));
    }
}
