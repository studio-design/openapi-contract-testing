<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Support;

use const FILTER_VALIDATE_INT;

use function array_map;
use function array_values;
use function filter_var;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function preg_match;
use function strtolower;

final class TypeCoercer
{
    /**
     * Pick the first primitive type from an OAS 3.1 multi-type declaration,
     * skipping `null`. Returns `null` if no usable string type is found.
     *
     * @param array<int|string, mixed> $types
     */
    public static function firstPrimitiveType(array $types): ?string
    {
        foreach ($types as $candidate) {
            if (is_string($candidate) && $candidate !== 'null') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Scalar-only variant used for path / header parameters. The input arrives
     * as a single string (OpenAPI default `style: simple`) so array handling
     * is never appropriate — a spec declaring `type: array` for such a param
     * would be rejected by opis because the request value is still scalar.
     *
     * @param array<string, mixed> $schema
     */
    public static function coercePrimitive(mixed $value, array $schema): mixed
    {
        $type = $schema['type'] ?? null;

        if (is_array($type)) {
            $type = self::firstPrimitiveType($type);
        }

        return self::coercePrimitiveFromType($value, $type);
    }

    /**
     * Shared scalar coercion: string → int/float/bool when the target type is
     * clean, otherwise the original value passes through so opis can report a
     * meaningful type mismatch.
     */
    public static function coercePrimitiveFromType(mixed $value, mixed $type): mixed
    {
        if (!is_string($value) || !is_string($type)) {
            return $value;
        }

        return match ($type) {
            'integer' => self::coerceToInt($value),
            'number' => is_numeric($value) ? (float) $value : $value,
            'boolean' => match (strtolower($value)) {
                'true' => true,
                'false' => false,
                default => $value,
            },
            default => $value,
        };
    }

    /**
     * Conservatively coerce a query string value to the type declared by the
     * schema. When the string is not a clean representation of the target
     * type, the original value is returned unchanged so opis can surface a
     * meaningful type error rather than silently passing.
     *
     * For multi-type schemas (OAS 3.1 `type: ["integer", "null"]`) the first
     * non-`null` primitive type is used as the coercion target. For
     * `type: array`, each item is coerced against the declared `items` schema.
     *
     * @param array<string, mixed> $schema
     */
    public static function coerceQuery(mixed $value, array $schema): mixed
    {
        $type = $schema['type'] ?? null;

        if (is_array($type)) {
            $type = self::firstPrimitiveType($type);
        }

        if ($type === 'array') {
            $value = is_array($value) ? array_values($value) : [$value];

            $itemSchema = $schema['items'] ?? null;
            if (is_array($itemSchema)) {
                return array_map(static fn(mixed $item): mixed => self::coerceQuery($item, $itemSchema), $value);
            }

            return $value;
        }

        return self::coercePrimitiveFromType($value, $type);
    }

    /**
     * Coerce a URL-sourced string to int.
     *
     * `filter_var(FILTER_VALIDATE_INT)` is too permissive for contract testing:
     * it accepts leading/trailing whitespace (e.g. "5 " → 5) and a leading
     * sign prefix ("+5" → 5). Combined with rawurldecode these laundering
     * behaviours would silently pass non-canonical URLs — real servers
     * typically reject them, creating silent drift between the test harness
     * and production. Pre-filter with a strict canonical-integer regex:
     * optional leading `-`, then either `0` or a digit string without a
     * leading zero. Anything else falls through unchanged so opis can
     * report a meaningful type error.
     *
     * Overflow is still handled by `filter_var` returning `false` for
     * values exceeding PHP_INT_MAX/MIN.
     */
    public static function coerceToInt(string $value): int|string
    {
        if (preg_match('/^-?(0|[1-9]\d*)$/', $value) !== 1) {
            return $value;
        }

        $result = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($result) ? $result : $value;
    }
}
