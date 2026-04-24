<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use function array_key_exists;
use function explode;
use function get_debug_type;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function rawurldecode;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function substr;

final class OpenApiRefResolver
{
    /**
     * Resolve all internal `$ref` entries in the spec in place and return the
     * same array. Any structural problem with a `$ref` throws
     * `InvalidOpenApiSpecException` so users get one actionable error at
     * load time instead of a cryptic opis failure surfacing deep inside
     * validation. See `InvalidOpenApiSpecReason` for the exhaustive list of
     * failure categories produced here.
     *
     * The input array is mutated: on a successful resolve the returned value
     * is the same array with `$ref` nodes substituted. On throw, the partially
     * mutated state is discarded at the caller (`OpenApiSpecLoader::load()`
     * only caches the result after `resolve()` returns cleanly).
     *
     * @param array<string, mixed> $spec
     *
     * @return array<string, mixed>
     *
     * @throws InvalidOpenApiSpecException when a `$ref` cannot be resolved
     */
    public static function resolve(array $spec): array
    {
        // $root is a frozen snapshot used for pointer lookups. PHP array
        // copy-on-write keeps it untouched as we mutate $spec via $node refs.
        $root = $spec;
        self::walk($spec, $root, []);

        return $spec;
    }

    /**
     * @param array<int|string, mixed> $node
     * @param array<string, mixed> $root
     * @param list<string> $chain pointer-refs already on the resolution stack — used to detect cycles
     * @param bool $insidePropertiesMap true when `$node` is the direct children dict of
     *                                  a `properties` / `patternProperties` map, where keys are property names
     *                                  rather than schema keywords. The flag resets one level deeper, because
     *                                  each named entry is itself a schema.
     */
    private static function walk(array &$node, array $root, array $chain, bool $insidePropertiesMap = false): void
    {
        if (!$insidePropertiesMap && array_key_exists('$ref', $node)) {
            $ref = $node['$ref'];

            if (!is_string($ref)) {
                throw new InvalidOpenApiSpecException(
                    InvalidOpenApiSpecReason::NonStringRef,
                    sprintf('Invalid $ref: expected string, got %s', get_debug_type($ref)),
                );
            }

            if ($ref === '#/' || $ref === '#') {
                // A bare root pointer substitutes the entire spec in place,
                // which triggers unbounded recursion before cycle detection
                // can help. Reject with a specific message so the author
                // doesn't chase a confusing "Circular" error.
                throw new InvalidOpenApiSpecException(
                    InvalidOpenApiSpecReason::RootPointerRef,
                    'Invalid $ref: root pointer "' . $ref . '" is not a reference to a definition',
                    ref: $ref,
                );
            }

            if (!str_starts_with($ref, '#/')) {
                if (str_starts_with($ref, '#')) {
                    throw new InvalidOpenApiSpecException(
                        InvalidOpenApiSpecReason::BareFragmentRef,
                        sprintf('Invalid $ref: bare fragment %s is not a JSON Pointer (expected "#/..." form)', $ref),
                        ref: $ref,
                    );
                }

                throw new InvalidOpenApiSpecException(
                    InvalidOpenApiSpecReason::ExternalRef,
                    sprintf(
                        'External $ref is not supported: %s. Only internal refs (#/...) are resolved; '
                        . 'bundle external files with a tool like `redocly bundle` before loading.',
                        $ref,
                    ),
                    ref: $ref,
                );
            }

            if (in_array($ref, $chain, true)) {
                throw new InvalidOpenApiSpecException(
                    InvalidOpenApiSpecReason::CircularRef,
                    sprintf('Circular $ref detected: %s', implode(' -> ', [...$chain, $ref])),
                    ref: $ref,
                );
            }

            [$found, $target] = self::lookup($ref, $root);
            if (!$found) {
                throw new InvalidOpenApiSpecException(
                    InvalidOpenApiSpecReason::UnresolvableRef,
                    sprintf('Unresolvable $ref: target not found for %s', $ref),
                    ref: $ref,
                );
            }

            if (!is_array($target)) {
                throw new InvalidOpenApiSpecException(
                    InvalidOpenApiSpecReason::NonObjectRefTarget,
                    sprintf('$ref target is not an object: %s points to a %s value', $ref, get_debug_type($target)),
                    ref: $ref,
                );
            }

            // Push $ref onto the chain before recursing so nested self-references
            // are detected as cycles; then replace the node entirely. Sibling
            // keys alongside $ref are dropped per OAS 3.0 ("any sibling
            // elements of a $ref are ignored"), which is a safe subset of 3.1.
            self::walk($target, $root, [...$chain, $ref]);
            $node = $target;

            return;
        }

        foreach ($node as $key => &$child) {
            if (is_array($child)) {
                // additionalProperties is intentionally excluded: its value is a single
                // schema (not a dict of schemas), so a direct $ref under it is a
                // legitimate Reference Object that must resolve.
                $childInsidePropertiesMap = $key === 'properties' || $key === 'patternProperties';
                self::walk($child, $root, $chain, $childInsidePropertiesMap);
            }
        }
        unset($child);
    }

    /**
     * Returns `[found, value]` where `found` disambiguates a missing segment
     * from a literal `null` leaf — both of which could otherwise be the same
     * `null` return and silently misroute the error message.
     *
     * @param array<string, mixed> $root
     *
     * @return array{0: bool, 1: mixed}
     */
    private static function lookup(string $ref, array $root): array
    {
        $pointer = substr($ref, 2);
        if ($pointer === '') {
            return [true, $root];
        }

        $segments = explode('/', $pointer);

        $node = $root;
        foreach ($segments as $segment) {
            $segment = self::unescapePointerSegment($segment);

            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return [false, null];
            }

            $node = $node[$segment];
        }

        return [true, $node];
    }

    /**
     * `~1` must be decoded before `~0` so a literal `~1` stored in the key
     * round-trips correctly. `rawurldecode` runs first so percent-encoded
     * segments produced by URL-aware tooling also resolve.
     */
    private static function unescapePointerSegment(string $segment): string
    {
        $segment = rawurldecode($segment);
        $segment = str_replace('~1', '/', $segment);

        return str_replace('~0', '~', $segment);
    }
}
