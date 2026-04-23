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
     * Resolve all internal `$ref` entries in the spec in place and return it.
     *
     * External refs (cross-file, URL), circular refs, and dangling internal refs
     * throw `RuntimeException` so the user gets an early, actionable error at
     * load time instead of a cryptic opis failure deep in validation.
     *
     * @param array<string, mixed> $spec
     *
     * @return array<string, mixed>
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
     * @param list<string> $chain absolute refs currently being resolved
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

            if (!str_starts_with($ref, '#/')) {
                throw new RuntimeException(sprintf(
                    'External $ref is not supported in phase 1: %s. Only internal refs (#/...) are resolved; '
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

            $target = self::lookup($ref, $root);
            if ($target === null) {
                throw new RuntimeException(sprintf('Unresolvable $ref: target not found for %s', $ref));
            }

            if (!is_array($target)) {
                throw new RuntimeException(sprintf(
                    '$ref target is not an object: %s points to a %s value',
                    $ref,
                    get_debug_type($target),
                ));
            }

            // Recurse into the copied target so nested refs resolve with the
            // current ref pushed onto the chain for cycle detection.
            self::walk($target, $root, [...$chain, $ref]);

            // Replace the node entirely. Sibling keys alongside $ref are
            // dropped per OAS 3.0 semantics ("any sibling elements of a $ref
            // are ignored"); the same behaviour is a safe subset of OAS 3.1.
            $node = $target;

            return;
        }

        foreach ($node as &$child) {
            if (is_array($child)) {
                self::walk($child, $root, $chain);
            }
        }
    }

    /**
     * Resolve a local JSON Pointer (e.g. `#/components/schemas/Foo`) against
     * the root spec. Returns `null` when any segment is missing.
     *
     * @param array<string, mixed> $root
     */
    private static function lookup(string $ref, array $root): mixed
    {
        $pointer = substr($ref, 2);
        if ($pointer === '') {
            return $root;
        }

        $segments = explode('/', $pointer);

        $node = $root;
        foreach ($segments as $segment) {
            $segment = self::unescapePointerSegment($segment);

            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * Decode a single JSON Pointer segment per RFC 6901, additionally tolerating
     * URL-encoded input (e.g. `%20`). Order matters: `~1` must be decoded before
     * `~0` so a literal `~1` in the key survives round-tripping.
     */
    private static function unescapePointerSegment(string $segment): string
    {
        $segment = rawurldecode($segment);
        $segment = str_replace('~1', '/', $segment);

        return str_replace('~0', '~', $segment);
    }
}
