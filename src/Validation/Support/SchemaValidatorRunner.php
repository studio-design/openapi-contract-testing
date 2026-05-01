<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Support;

use const PHP_INT_MAX;

use InvalidArgumentException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\JsonPointer;
use Opis\JsonSchema\Validator;
use stdClass;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_values;
use function count;
use function get_object_vars;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function property_exists;
use function sprintf;

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

        $cascadeActions = self::computeCascadeActions($error, $jsonSchema);

        return self::applyCascadeActions(
            $this->errorFormatter->format($error),
            $cascadeActions,
        );
    }

    /**
     * Walk the opis ValidationError tree, identify `additionalProperties`
     * errors that are cascade artifacts of opis's `PropertiesKeyword` skipping
     * its `addCheckedProperties()` call when any sub-property fails (see issue
     * #159 for the upstream root cause), and return a per-path map of which
     * property names ARE genuinely additional — i.e. survive the dedup.
     *
     * Empty list at a path = whole `additionalProperties` message will be
     * dropped at format-time. Path absent from the map = no cascade action
     * (message kept as-is). The map is consumed by {@see applyCascadeActions()}.
     *
     * Detection is structural — the listed property names come straight from
     * `ValidationError::args()['properties']` (the raw array opis populates
     * before string interpolation), and declared property names come from
     * walking `$jsonSchema` via the error's raw `fullPath()` segments. Neither
     * step parses or trims the rendered error message, so property names
     * containing commas / spaces / empty strings / JSON-Pointer-escape-worthy
     * characters all compare correctly against the declared set.
     *
     * Degrades safely:
     * - Non-object root schema (opis accepts `true`/`false`/scalar) → empty map
     * - Composition keywords (`oneOf` / `allOf` / `anyOf`) routing the data
     *   through an alternate sub-schema → walker returns null at that path,
     *   no entry in the map, message kept untouched.
     *
     * @return array<string, list<string>>
     */
    private static function computeCascadeActions(ValidationError $rootError, mixed $jsonSchema): array
    {
        if (!$jsonSchema instanceof stdClass) {
            return [];
        }

        $actions = [];
        self::collectCascadeActions($rootError, $jsonSchema, $actions);

        return $actions;
    }

    /**
     * @param array<string, list<string>> $actions
     */
    private static function collectCascadeActions(
        ValidationError $error,
        stdClass $rootSchema,
        array &$actions,
    ): void {
        if ($error->keyword() === 'additionalProperties') {
            $listed = $error->args()['properties'] ?? null;
            if (is_array($listed)) {
                $segments = $error->data()->fullPath();
                $declared = self::declaredPropertyNamesAtSegments($rootSchema, $segments);
                if ($declared !== null) {
                    $real = array_values(array_filter(
                        $listed,
                        static fn(mixed $name): bool => is_string($name) && !in_array($name, $declared, true),
                    ));

                    // Only record an action when the cascade actually
                    // contracts the list — pure-real-additionals report
                    // unchanged so we don't pay the format-pass cost or
                    // risk re-encoding the message.
                    if (count($real) < count($listed)) {
                        $actions[JsonPointer::pathToString($segments)] = $real;
                    }
                }
            }
        }

        foreach ($error->subErrors() as $sub) {
            self::collectCascadeActions($sub, $rootSchema, $actions);
        }
    }

    /**
     * Apply the cascade-action map to the formatted errors. For each entry in
     * the map, find the `additionalProperties` line at that path and either
     * drop it (when the kept list is empty) or rewrite it with only the
     * genuinely-additional names. Sibling messages at the same path (e.g. a
     * `required` failure that fired in the same object) are preserved.
     *
     * The detection of which message line is the cascade target uses a regex
     * against opis's English template (`Additional object properties are not
     * allowed: ...`). If opis ever rewords this template, the regex stops
     * matching and we leave the messages unchanged — fail-safe in the noisy
     * direction (no silent suppression of real violations). This is the only
     * string-based step in the pipeline; the property-name comparison itself
     * is fully structural.
     *
     * @param array<string, string[]> $errors
     * @param array<string, list<string>> $actions
     *
     * @return array<string, string[]>
     */
    private static function applyCascadeActions(array $errors, array $actions): array
    {
        if ($actions === []) {
            return $errors;
        }

        foreach ($errors as $path => $messages) {
            if (!array_key_exists($path, $actions)) {
                continue;
            }

            $real = $actions[$path];
            $kept = [];
            foreach ($messages as $message) {
                if (preg_match('/^Additional object properties are not allowed: /', $message) !== 1) {
                    $kept[] = $message;

                    continue;
                }

                if ($real === []) {
                    // Whole message is cascade artifact — suppress.
                    continue;
                }

                $kept[] = sprintf(
                    'Additional object properties are not allowed: %s',
                    implode(', ', $real),
                );
            }

            if ($kept === []) {
                unset($errors[$path]);
            } else {
                $errors[$path] = $kept;
            }
        }

        return $errors;
    }

    /**
     * Walk `$schema` via raw data-path segments and return the keys of the
     * `properties` keyword at that location, or null when the path doesn't
     * resolve through plain property nesting (e.g. composition keywords,
     * `additionalProperties: <schema>`, missing keyword).
     *
     * Conservative: only steps through `properties.<name>`. Segments are
     * compared as raw strings — opis already gives us decoded segments via
     * `DataInfo::fullPath()`, so a property declared as `a/b` matches without
     * any JSON-Pointer escape handling on our side.
     *
     * @param array<int, mixed> $segments
     *
     * @return null|list<string>
     */
    private static function declaredPropertyNamesAtSegments(stdClass $schema, array $segments): ?array
    {
        $current = $schema;

        foreach ($segments as $segment) {
            if (!property_exists($current, 'properties') || !$current->properties instanceof stdClass) {
                return null;
            }
            $key = (string) $segment;
            if (!property_exists($current->properties, $key)) {
                return null;
            }
            $next = $current->properties->{$key};
            if (!$next instanceof stdClass) {
                return null;
            }
            $current = $next;
        }

        if (!property_exists($current, 'properties') || !$current->properties instanceof stdClass) {
            return null;
        }

        return array_keys(get_object_vars($current->properties));
    }
}
