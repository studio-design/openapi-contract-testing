<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use Studio\OpenApiContractTesting\Internal\ExternalRefLoader;

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
use function strpos;
use function substr;

final class OpenApiRefResolver
{
    /**
     * Resolve every `$ref` entry in the spec in place and return the same
     * array. Any structural problem with a `$ref` throws
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
     * Pass `$sourceFile` (the absolute path of the spec file being loaded)
     * to enable resolution of external `$ref` targets located on the local
     * filesystem (e.g. `./schemas/pet.yaml`). When `$sourceFile` is `null`,
     * any non-internal `$ref` triggers `LocalRefRequiresSourceFile` so the
     * caller knows it must thread the path through. HTTP(S) and `file://`
     * refs are always rejected — the former is reserved for a future PR
     * with explicit opt-in semantics, the latter for security.
     *
     * @param array<string, mixed> $spec
     *
     * @return array<string, mixed>
     *
     * @throws InvalidOpenApiSpecException when a `$ref` cannot be resolved
     */
    public static function resolve(array $spec, ?string $sourceFile = null): array
    {
        // $root is a frozen snapshot used for pointer lookups. PHP array
        // copy-on-write keeps it untouched as we mutate $spec via $node refs.
        $root = $spec;
        // Per-resolution external file cache, keyed by canonical absolute
        // path. Sibling refs into the same file decode it once.
        $documentCache = [];
        self::walk($spec, $root, [], false, $sourceFile, $documentCache);

        return $spec;
    }

    /**
     * @param array<int|string, mixed> $node
     * @param array<string, mixed> $root currently-walked document's root
     * @param list<string> $chain canonical pointer-refs already on the resolution stack — used to detect cycles
     * @param bool $insidePropertiesMap true when `$node` is the direct children dict of
     *                                  a `properties` / `patternProperties` map, where keys are property names
     *                                  rather than schema keywords. The flag resets one level deeper, because
     *                                  each named entry is itself a schema.
     * @param ?string $currentSourceFile absolute path of the file owning `$root`, or null in legacy calls
     * @param array<string, array<string, mixed>> $documentCache external document cache for this resolution
     */
    private static function walk(
        array &$node,
        array $root,
        array $chain,
        bool $insidePropertiesMap,
        ?string $currentSourceFile,
        array &$documentCache,
    ): void {
        if (!$insidePropertiesMap && array_key_exists('$ref', $node)) {
            $ref = $node['$ref'];

            if (!is_string($ref)) {
                throw new InvalidOpenApiSpecException(
                    InvalidOpenApiSpecReason::NonStringRef,
                    sprintf('Invalid $ref: expected string, got %s', get_debug_type($ref)),
                );
            }

            self::resolveRef($node, $ref, $root, $chain, $currentSourceFile, $documentCache);

            return;
        }

        foreach ($node as $key => &$child) {
            if (is_array($child)) {
                // additionalProperties is intentionally excluded: its value is a single
                // schema (not a dict of schemas), so a direct $ref under it is a
                // legitimate Reference Object that must resolve.
                $childInsidePropertiesMap = $key === 'properties' || $key === 'patternProperties';
                self::walk($child, $root, $chain, $childInsidePropertiesMap, $currentSourceFile, $documentCache);
            }
        }
        unset($child);
    }

    /**
     * Dispatch a `$ref` to the right resolution path: internal pointer
     * lookup against the current document, or external file load. URL
     * schemes that this PR cannot honour (`http://`, `https://`,
     * `file://`) throw with dedicated reasons so consumers can branch
     * on `$reason` instead of substring-matching the message.
     *
     * @param array<int|string, mixed> $node
     * @param array<string, mixed> $root
     * @param list<string> $chain
     * @param array<string, array<string, mixed>> $documentCache
     */
    private static function resolveRef(
        array &$node,
        string $ref,
        array $root,
        array $chain,
        ?string $currentSourceFile,
        array &$documentCache,
    ): void {
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

        if (str_starts_with($ref, 'file://')) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::FileSchemeNotSupported,
                sprintf(
                    '`file://` $ref is not supported for security reasons: %s. '
                    . 'Use a relative or absolute filesystem path instead.',
                    $ref,
                ),
                ref: $ref,
            );
        }

        if (str_starts_with($ref, 'http://') || str_starts_with($ref, 'https://')) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::RemoteRefNotImplemented,
                sprintf(
                    'HTTP(S) $ref is not yet supported: %s. '
                    . 'This will be added in a future release with an opt-in PSR-18 client.',
                    $ref,
                ),
                ref: $ref,
            );
        }

        if (str_starts_with($ref, '#/')) {
            self::resolveInternalRef($node, $ref, $root, $chain, $currentSourceFile, $documentCache);

            return;
        }

        if (str_starts_with($ref, '#')) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::BareFragmentRef,
                sprintf('Invalid $ref: bare fragment %s is not a JSON Pointer (expected "#/..." form)', $ref),
                ref: $ref,
            );
        }

        // Anything else is an external filesystem ref.
        if ($currentSourceFile === null) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::LocalRefRequiresSourceFile,
                sprintf(
                    'External $ref %s cannot be resolved because the resolver was '
                    . 'invoked without a source file. Pass $sourceFile to OpenApiRefResolver::resolve() '
                    . '(OpenApiSpecLoader does this automatically).',
                    $ref,
                ),
                ref: $ref,
            );
        }

        self::resolveExternalRef($node, $ref, $chain, $currentSourceFile, $documentCache);
    }

    /**
     * @param array<int|string, mixed> $node
     * @param array<string, mixed> $root
     * @param list<string> $chain
     * @param array<string, array<string, mixed>> $documentCache
     */
    private static function resolveInternalRef(
        array &$node,
        string $ref,
        array $root,
        array $chain,
        ?string $currentSourceFile,
        array &$documentCache,
    ): void {
        // Canonicalize internal refs against the current document so cycles
        // that span files are detected against per-file pointers, not the
        // raw `#/...` string (which is ambiguous across documents).
        $chainKey = self::canonicalChainKey($currentSourceFile, $ref);

        if (in_array($chainKey, $chain, true)) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::CircularRef,
                sprintf('Circular $ref detected: %s', implode(' -> ', [...$chain, $chainKey])),
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

        // Push canonicalized ref onto the chain before recursing so nested
        // self-references are detected as cycles; then replace the node
        // entirely. Sibling keys alongside $ref are dropped per OAS 3.0
        // ("any sibling elements of a $ref are ignored"), which is a safe
        // subset of 3.1.
        self::walk($target, $root, [...$chain, $chainKey], false, $currentSourceFile, $documentCache);
        $node = $target;
    }

    /**
     * @param array<int|string, mixed> $node
     * @param list<string> $chain
     * @param array<string, array<string, mixed>> $documentCache
     */
    private static function resolveExternalRef(
        array &$node,
        string $ref,
        array $chain,
        string $currentSourceFile,
        array &$documentCache,
    ): void {
        [$pathPart, $fragment] = self::splitRef($ref);
        $document = ExternalRefLoader::loadDocument($pathPart, $currentSourceFile, $documentCache);
        $absolutePath = $document['absolutePath'];
        $newRoot = $document['decoded'];

        if ($fragment !== '') {
            $internalRef = '#' . $fragment;
            $chainKey = self::canonicalChainKey($absolutePath, $internalRef);

            if (in_array($chainKey, $chain, true)) {
                throw new InvalidOpenApiSpecException(
                    InvalidOpenApiSpecReason::CircularRef,
                    sprintf('Circular $ref detected: %s', implode(' -> ', [...$chain, $chainKey])),
                    ref: $ref,
                );
            }

            [$found, $target] = self::lookup($internalRef, $newRoot);
            if (!$found) {
                throw new InvalidOpenApiSpecException(
                    InvalidOpenApiSpecReason::UnresolvableRef,
                    sprintf('Unresolvable $ref: fragment %s not found in %s', $fragment, $absolutePath),
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

            self::walk($target, $newRoot, [...$chain, $chainKey], false, $absolutePath, $documentCache);
            $node = $target;

            return;
        }

        // Whole-file ref: chain key is the absolute path; the document
        // root replaces the node, and walking continues against that root.
        $chainKey = $absolutePath;
        if (in_array($chainKey, $chain, true)) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::CircularRef,
                sprintf('Circular $ref detected: %s', implode(' -> ', [...$chain, $chainKey])),
                ref: $ref,
            );
        }

        $target = $newRoot;
        self::walk($target, $newRoot, [...$chain, $chainKey], false, $absolutePath, $documentCache);
        $node = $target;
    }

    /**
     * Split a `$ref` like `./schemas/pet.yaml#/components/schemas/Pet` into
     * its path part and fragment. The fragment is returned without the
     * leading `#` so callers can decide how to combine it with the loaded
     * document's root.
     *
     * @return array{0: string, 1: string}
     */
    private static function splitRef(string $ref): array
    {
        $hashPos = strpos($ref, '#');
        if ($hashPos === false) {
            return [$ref, ''];
        }

        return [substr($ref, 0, $hashPos), substr($ref, $hashPos + 1)];
    }

    private static function canonicalChainKey(?string $sourceFile, string $ref): string
    {
        return ($sourceFile ?? '') . $ref;
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
