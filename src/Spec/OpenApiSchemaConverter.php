<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Spec;

use const E_USER_WARNING;

use Studio\OpenApiContractTesting\OpenApiVersion;
use Studio\OpenApiContractTesting\SchemaContext;

use function array_is_list;
use function array_key_exists;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;
use function trigger_error;

final class OpenApiSchemaConverter
{
    /**
     * OAS keys to remove for both 3.0 and 3.1.
     *
     * `$schema` is stripped because the converter's output targets Draft 07
     * (the SchemaValidatorRunner pins opis's default to 07). A spec author
     * who inlines `$schema: ".../2020-12/schema"` (legitimate per OAS 3.1
     * `jsonSchemaDialect`) would otherwise force opis to interpret the
     * already-lowered schema under 2020-12, where the array-form `items`
     * we emit for `prefixItems` is invalid. Stripping keeps the validator
     * draft consistent with what the converter actually produces.
     */
    private const OPENAPI_COMMON_KEYS = [
        'discriminator',
        'xml',
        'externalDocs',
        'example',
        'examples',
        'deprecated',
        '$schema',
    ];

    /** OAS 3.0 specific keys (not in JSON Schema Draft 07) */
    private const OPENAPI_3_0_KEYS = [
        'nullable',
        'readOnly',
        'writeOnly',
    ];

    /** Draft 2020-12 keys that don't exist in Draft 07 */
    private const DRAFT_2020_12_KEYS = [
        '$dynamicRef',
        '$dynamicAnchor',
        'contentSchema',
    ];

    /**
     * 2019-09 / 2020-12 keywords opis Draft 07 truly does not implement.
     * Pre-fix, this set wrongly included `patternProperties`,
     * `contentMediaType`, and `contentEncoding` — opis Draft 06+ DOES
     * implement those (see vendor/opis/json-schema/src/Parsers/Drafts/Draft06.php),
     * so warning that "the contract is NOT being enforced" was misinformation.
     * Only `unevaluatedProperties` and `unevaluatedItems` are genuinely
     * dropped silently by Draft 07 — those keep the warning so users notice.
     */
    private const DRAFT_2020_12_UNSUPPORTED_KEYS = [
        'unevaluatedProperties',
        'unevaluatedItems',
    ];

    /** @var array<string, true> */
    private static array $warnedKeywords = [];

    /**
     * Convert an OpenAPI schema to a JSON Schema Draft 07 compatible schema.
     *
     * `$context` drives asymmetric handling of `readOnly` / `writeOnly`:
     * in `Request` context, `readOnly` properties are turned into forbidden
     * subschemas (boolean `false`) and stripped from `required`; in `Response`
     * context the same happens for `writeOnly`. See `SchemaContext` for the
     * motivating OpenAPI semantics.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    public static function convert(
        array $schema,
        OpenApiVersion $version = OpenApiVersion::V3_0,
        SchemaContext $context = SchemaContext::Response,
    ): array {
        self::convertInPlace($schema, $version, $context);

        return $schema;
    }

    /**
     * Reset the per-process "already-warned" set used by
     * {@see warnIfUsesUnsupportedKeywords()}. Test seam — production code
     * never needs this.
     *
     * @internal
     */
    public static function resetWarningStateForTesting(): void
    {
        self::$warnedKeywords = [];
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function convertInPlace(array &$schema, OpenApiVersion $version, SchemaContext $context): void
    {
        if ($version === OpenApiVersion::V3_0) {
            self::handleNullable($schema);
        } else {
            self::handlePrefixItems($schema);
            self::lowerConstToEnum($schema);
            self::removeKeys($schema, self::DRAFT_2020_12_KEYS);
        }

        // Warn for both 3.0 and 3.1: `patternProperties` and friends are valid
        // Draft 04/07 keywords used in 3.0 specs as well, so silent ignoring is
        // just as risky there.
        self::warnIfUsesUnsupportedKeywords($schema);

        self::removeKeys($schema, self::OPENAPI_COMMON_KEYS);

        // Enforce readOnly/writeOnly on this object's own `properties` before
        // recursing: the recursive call's 3.0 key scrub will strip the child's
        // top-level marker, so an enforcement pass that looks at child
        // subschemas only sees the keyword while we are still at the parent.
        // It also avoids needlessly descending into a subtree we're about to
        // replace with boolean `false`.
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            self::enforceContextOnProperties($schema, $context);
        }

        if ($version === OpenApiVersion::V3_0) {
            self::removeKeys($schema, self::OPENAPI_3_0_KEYS);
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as &$property) {
                if (is_array($property)) {
                    self::convertInPlace($property, $version, $context);
                }
            }
            unset($property);
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            if (array_is_list($schema['items'])) {
                foreach ($schema['items'] as &$item) {
                    if (is_array($item)) {
                        self::convertInPlace($item, $version, $context);
                    }
                }
                unset($item);
            } else {
                self::convertInPlace($schema['items'], $version, $context);
            }
        }

        foreach (['allOf', 'oneOf', 'anyOf'] as $combiner) {
            if (isset($schema[$combiner]) && is_array($schema[$combiner])) {
                foreach ($schema[$combiner] as &$item) {
                    if (is_array($item)) {
                        self::convertInPlace($item, $version, $context);
                    }
                }
                unset($item);
            }
        }

        if (isset($schema['additionalProperties']) && is_array($schema['additionalProperties'])) {
            self::convertInPlace($schema['additionalProperties'], $version, $context);
        }

        if (isset($schema['not']) && is_array($schema['not'])) {
            self::convertInPlace($schema['not'], $version, $context);
        }
    }

    /**
     * Replace the subschema of every context-forbidden property with boolean
     * `false` (Draft-07 canonical "this property must not appear") and prune
     * forbidden names from the parent `required` array.
     *
     * Detection only looks at the property's own top-level `readOnly` /
     * `writeOnly`; markers buried inside a property's `allOf` / `oneOf` /
     * `anyOf` children are not handled (known limitation).
     *
     * @param array<string, mixed> $schema
     */
    private static function enforceContextOnProperties(array &$schema, SchemaContext $context): void
    {
        /** @var array<string, mixed> $properties */
        $properties = $schema['properties'];
        $forbiddenKeyword = $context->forbiddenKeyword();
        $forbiddenNames = [];

        foreach ($properties as $name => $property) {
            if (!is_array($property)) {
                continue;
            }

            if (($property[$forbiddenKeyword] ?? null) !== true) {
                continue;
            }

            $properties[$name] = false;
            $forbiddenNames[] = $name;
        }

        if ($forbiddenNames === []) {
            return;
        }

        $schema['properties'] = $properties;

        if (!isset($schema['required']) || !is_array($schema['required'])) {
            return;
        }

        /** @var array<int, mixed> $required */
        $required = $schema['required'];
        $filtered = [];
        foreach ($required as $entry) {
            if (is_string($entry) && in_array($entry, $forbiddenNames, true)) {
                continue;
            }
            $filtered[] = $entry;
        }

        if ($filtered === []) {
            unset($schema['required']);

            return;
        }

        $schema['required'] = $filtered;
    }

    /**
     * Convert OpenAPI 3.0 nullable to JSON Schema compatible type.
     *
     * When `enum` is present alongside `nullable: true`, append `null` to the
     * enum so opis accepts null values — this matches the OAS 3.0 convention
     * (most spec authors don't list null inside `enum` even when nullable is on).
     *
     * @param array<string, mixed> $schema
     */
    private static function handleNullable(array &$schema): void
    {
        if (!isset($schema['nullable']) || $schema['nullable'] !== true) {
            return;
        }

        unset($schema['nullable']);

        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array(null, $schema['enum'], true)) {
            $schema['enum'][] = null;
        }

        if (isset($schema['type']) && is_string($schema['type'])) {
            $schema['type'] = [$schema['type'], 'null'];

            return;
        }

        foreach (['oneOf', 'anyOf'] as $combiner) {
            if (isset($schema[$combiner]) && is_array($schema[$combiner])) {
                $schema[$combiner][] = ['type' => 'null'];

                return;
            }
        }

        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            $allOf = $schema['allOf'];
            unset($schema['allOf']);
            $schema['oneOf'] = [
                ['allOf' => $allOf],
                ['type' => 'null'],
            ];
        }
    }

    /**
     * OAS 3.1 / Draft 2019-09 introduced `const`. Draft 07 (the schema dialect
     * we delegate to via opis) does not understand it, so a `const: "fixed"`
     * schema would silently accept any value of the correct type. Lower to
     * `enum: [value]` so the constraint is actually enforced.
     *
     * Conflict policy: when both `const` and `enum` are present (rare and
     * arguably malformed), we keep `enum` and drop `const`. JSON Schema
     * semantics are that the two intersect — the result should equal `[const]`
     * if `const ∈ enum`, else unsatisfiable — but reproducing that precisely
     * is more delicate than v1.0 needs. The chosen behaviour LOOSENS the
     * constraint relative to the spec; treat this conflict as a known
     * limitation and prefer `const`-only or `enum`-only schemas.
     *
     * @param array<string, mixed> $schema
     */
    private static function lowerConstToEnum(array &$schema): void
    {
        if (!array_key_exists('const', $schema)) {
            return;
        }

        if (!array_key_exists('enum', $schema)) {
            $schema['enum'] = [$schema['const']];
        }

        unset($schema['const']);
    }

    /**
     * Issue a one-shot E_USER_WARNING for each Draft 2019-09 / 2020-12 keyword
     * we cannot honour through opis Draft 07. Without this, specs relying on
     * `patternProperties` / `unevaluatedProperties` / `contentMediaType` would
     * silently pass — a contract test that does not actually enforce the
     * contract is the worst possible failure mode.
     *
     * Warns once per keyword per process to avoid log spam in long test runs.
     *
     * @param array<string, mixed> $schema
     */
    private static function warnIfUsesUnsupportedKeywords(array $schema): void
    {
        foreach (self::DRAFT_2020_12_UNSUPPORTED_KEYS as $keyword) {
            if (!array_key_exists($keyword, $schema) || isset(self::$warnedKeywords[$keyword])) {
                continue;
            }

            self::$warnedKeywords[$keyword] = true;
            trigger_error(
                sprintf(
                    "[OpenAPI Schema] '%s' is not supported by the JSON Schema Draft 07 validator opis uses internally. The keyword will be ignored — your spec's constraint is NOT being enforced. Consider rewriting using Draft 07 equivalents (additionalProperties, required, etc.).",
                    $keyword,
                ),
                E_USER_WARNING,
            );
        }
    }

    /**
     * Convert Draft 2020-12 prefixItems to Draft 07 items array (tuple validation).
     *
     * @param array<string, mixed> $schema
     */
    private static function handlePrefixItems(array &$schema): void
    {
        if (isset($schema['prefixItems']) && is_array($schema['prefixItems'])) {
            $schema['items'] = $schema['prefixItems'];
            unset($schema['prefixItems']);
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param string[] $keys
     */
    private static function removeKeys(array &$schema, array $keys): void
    {
        foreach ($keys as $key) {
            unset($schema[$key]);
        }
    }
}
