<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Support;

use const PHP_INT_MAX;

use InvalidArgumentException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use stdClass;

use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function explode;
use function get_object_vars;
use function implode;
use function in_array;
use function preg_match;
use function property_exists;
use function sprintf;
use function trim;

/**
 * @internal Not part of the package's public API. Do not use from user code.
 */
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
        // opis defaults to draft 2020-12 when a schema doesn't declare `$schema`.
        // OpenApiSchemaConverter targets Draft 07 (e.g. it lowers `prefixItems`
        // to the array-form `items`, which is valid Draft 07 tuple validation
        // but rejected by 2020-12). Forcing Draft 07 here keeps the converter
        // and the validator in agreement and unblocks tuple validation for
        // OAS 3.1 `prefixItems`.
        $this->opisValidator->parser()->setDefaultDraftVersion('07');
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

        return self::stripCascadingAdditionalProperties($this->errorFormatter->format($error), $jsonSchema);
    }

    /**
     * opis's `PropertiesKeyword::validate()` early-returns whenever any
     * sub-property fails its schema, which leaves the validation context
     * without `$checked`. The follow-on `additionalProperties: false` keyword
     * then sees every property the data carries as "unchecked" and fires a
     * paired pseudo-error naming declared properties as "additional" — see
     * issue #159 for the upstream root cause analysis.
     *
     * From the user's perspective, the second error reads as "these
     * properties are not allowed by the schema", which is the opposite of
     * what the schema actually says. In a large suite this routinely doubles
     * the failure count and points contributors at the wrong fix.
     *
     * The cleanup logic: for each `Additional object properties are not
     * allowed: a, b, c` message, drop names that ARE declared in the
     * schema's `properties` keyword at the cascade's path (those are cascade
     * artifacts). If every listed name is a cascade, drop the whole message;
     * otherwise rewrite it with the surviving genuinely-additional names so
     * the real signal still surfaces.
     *
     * Schema-based detection is required because the cascade reports
     * declared properties even when their sub-validation succeeded silently
     * (e.g. `message` is well-typed, but listed alongside `code` whose enum
     * failed). A purely sub-error-based heuristic would let those silently-
     * valid declared properties leak through as false-positive additionals.
     *
     * The strategy degrades safely: if `$jsonSchema` is not an object (opis
     * accepts `true`/`false`/scalar schemas too) or the cascade path can't
     * be resolved against it (composition keywords like `oneOf`/`allOf`
     * route data through alternate sub-schemas), the original messages are
     * returned unchanged — no false-positive suppression.
     *
     * @param array<string, string[]> $errors
     *
     * @return array<string, string[]>
     */
    private static function stripCascadingAdditionalProperties(array $errors, mixed $jsonSchema): array
    {
        if (!$jsonSchema instanceof stdClass) {
            return $errors;
        }

        $cleaned = [];

        foreach ($errors as $path => $messages) {
            $keptMessages = [];
            foreach ($messages as $message) {
                $matches = [];
                if (preg_match('/^Additional object properties are not allowed: (.+)$/', $message, $matches) !== 1) {
                    $keptMessages[] = $message;

                    continue;
                }

                $declared = self::declaredPropertyNamesAtPath($jsonSchema, $path);
                if ($declared === null) {
                    // Path didn't resolve to an object schema with a
                    // `properties` keyword we can inspect. Conservative:
                    // keep the message untouched.
                    $keptMessages[] = $message;

                    continue;
                }

                $listed = array_filter(
                    array_map(static fn(string $name): string => trim($name), explode(',', $matches[1])),
                    static fn(string $name): bool => $name !== '',
                );
                $real = array_values(array_filter(
                    $listed,
                    static fn(string $name): bool => !in_array($name, $declared, true),
                ));

                if ($real === []) {
                    continue;
                }

                $keptMessages[] = sprintf(
                    'Additional object properties are not allowed: %s',
                    implode(', ', $real),
                );
            }

            if ($keptMessages !== []) {
                $cleaned[$path] = $keptMessages;
            }
        }

        return $cleaned;
    }

    /**
     * Walk `$schema` via the JSON pointer in `$path` and return the keys of
     * the `properties` keyword at that location, or null when the path
     * doesn't resolve through plain property nesting (e.g. composition
     * keywords, `additionalProperties: <schema>`, missing schemas).
     *
     * Conservative: only steps through `properties.<name>`. If a path
     * segment can't be resolved that way the caller treats the cascade as
     * unsafe to dedup and keeps the message intact.
     *
     * @return null|list<string>
     */
    private static function declaredPropertyNamesAtPath(stdClass $schema, string $path): ?array
    {
        $current = $schema;

        if ($path !== '/' && $path !== '') {
            $segments = explode('/', trim($path, '/'));
            foreach ($segments as $segment) {
                if (!property_exists($current, 'properties') || !$current->properties instanceof stdClass) {
                    return null;
                }
                if (!property_exists($current->properties, $segment)) {
                    return null;
                }
                $next = $current->properties->{$segment};
                if (!$next instanceof stdClass) {
                    return null;
                }
                $current = $next;
            }
        }

        if (!property_exists($current, 'properties') || !$current->properties instanceof stdClass) {
            return null;
        }

        return array_keys(get_object_vars($current->properties));
    }
}
