<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use RuntimeException;

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
     * same array. External, circular, unresolvable, malformed, and root refs
     * all throw `RuntimeException` so users get one actionable error at load
     * time instead of a cryptic opis failure surfacing deep inside validation.
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
     * @throws RuntimeException on external / circular / unresolvable / malformed refs
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
     */
    private static function walk(array &$node, array $root, array $chain): void
    {
        if (array_key_exists('$ref', $node)) {
            $ref = $node['$ref'];

            if (!is_string($ref)) {
                throw new RuntimeException(sprintf(
                    'Invalid $ref: expected string, got %s',
                    get_debug_type($ref),
                ));
            }

            if ($ref === '#/' || $ref === '#') {
                // A bare root pointer substitutes the entire spec in place,
                // which triggers unbounded recursion before cycle detection
                // can help. Reject with a specific message so the author
                // doesn't chase a confusing "Circular" error.
                throw new RuntimeException('Invalid $ref: root pointer "' . $ref . '" is not a reference to a definition');
            }

            if (!str_starts_with($ref, '#/')) {
                if (str_starts_with($ref, '#')) {
                    throw new RuntimeException(sprintf(
                        'Invalid $ref: bare fragment %s is not a JSON Pointer (expected "#/..." form)',
                        $ref,
                    ));
                }

                throw new RuntimeException(sprintf(
                    'External $ref is not supported: %s. Only internal refs (#/...) are resolved; '
                    . 'bundle external files with a tool like `redocly bundle` before loading.',
                    $ref,
                ));
            }

            if (in_array($ref, $chain, true)) {
                throw new RuntimeException(sprintf(
                    'Circular $ref detected: %s',
                    implode(' -> ', [...$chain, $ref]),
                ));
            }

            [$found, $target] = self::lookup($ref, $root);
            if (!$found) {
                throw new RuntimeException(sprintf('Unresolvable $ref: target not found for %s', $ref));
            }

            if (!is_array($target)) {
                throw new RuntimeException(sprintf(
                    '$ref target is not an object: %s points to a %s value',
                    $ref,
                    get_debug_type($target),
                ));
            }

            // Push $ref onto the chain before recursing so nested self-references
            // are detected as cycles; then replace the node entirely. Sibling
            // keys alongside $ref are dropped per OAS 3.0 ("any sibling
            // elements of a $ref are ignored"), which is a safe subset of 3.1.
            self::walk($target, $root, [...$chain, $ref]);
            $node = $target;

            return;
        }

        foreach ($node as &$child) {
            if (is_array($child)) {
                self::walk($child, $root, $chain);
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
