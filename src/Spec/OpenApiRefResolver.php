<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Spec;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Studio\OpenApiContractTesting\Exception\InvalidOpenApiSpecException;
use Studio\OpenApiContractTesting\Exception\InvalidOpenApiSpecReason;
use Studio\OpenApiContractTesting\Internal\ExternalRefLoader;
use Studio\OpenApiContractTesting\Internal\HttpRefLoader;

use function array_key_exists;
use function array_pop;
use function explode;
use function get_debug_type;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function parse_url;
use function rawurldecode;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strpos;
use function strrpos;
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
     * any **filesystem-relative** `$ref` triggers `LocalRefRequiresSourceFile`.
     * HTTP(S) refs are dispatched via `$httpClient` regardless of `$sourceFile`.
     *
     * Pass `$httpClient` + `$requestFactory` (PSR-18 / PSR-17) AND
     * `$allowRemoteRefs: true` to permit HTTP(S) `$ref` resolution.
     * Without both, HTTP refs throw `RemoteRefDisallowed` (flag off) or
     * `HttpClientNotConfigured` (flag on but client/factory missing).
     * `file://` URLs are always rejected to keep path-resolution rules
     * predictable.
     *
     * @param array<string, mixed> $spec
     *
     * @return array<string, mixed>
     *
     * @throws InvalidOpenApiSpecException when a `$ref` cannot be resolved
     */
    public static function resolve(
        array $spec,
        ?string $sourceFile = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        bool $allowRemoteRefs = false,
    ): array {
        // OpenApiSpecLoader::configure() catches this earlier with an
        // InvalidArgumentException; this guard is for callers that
        // construct the resolver directly. Surface as the same reason
        // the per-ref path would fire so consumers can branch on a
        // single enum case.
        if ($allowRemoteRefs && ($httpClient === null || $requestFactory === null)) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::HttpClientNotConfigured,
                'OpenApiRefResolver::resolve(): allowRemoteRefs requires both '
                . 'a PSR-18 ClientInterface and a PSR-17 RequestFactoryInterface.',
            );
        }

        // After the guard above, allowRemoteRefs:true implies non-null
        // client + factory.
        $context = $allowRemoteRefs
            ? RefResolutionContext::withRemoteRefs($httpClient, $requestFactory, $sourceFile)
            : RefResolutionContext::filesystemOnly($sourceFile);

        // $root is a frozen snapshot used for pointer lookups. PHP array
        // copy-on-write keeps it untouched as we mutate $spec via $node refs.
        $root = $spec;
        // Per-resolution external file/URL cache, keyed by canonical absolute
        // path or canonical URL. Sibling refs into the same target decode it once.
        $documentCache = [];
        self::walk($spec, $root, [], false, $context, $documentCache);

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
     * @param array<string, array<string, mixed>> $documentCache external document cache for this resolution
     */
    private static function walk(
        array &$node,
        array $root,
        array $chain,
        bool $insidePropertiesMap,
        RefResolutionContext $context,
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

            self::resolveRef($node, $ref, $root, $chain, $context, $documentCache);

            return;
        }

        foreach ($node as $key => &$child) {
            if (is_array($child)) {
                // additionalProperties is intentionally excluded: its value is a single
                // schema (not a dict of schemas), so a direct $ref under it is a
                // legitimate Reference Object that must resolve.
                $childInsidePropertiesMap = $key === 'properties' || $key === 'patternProperties';
                self::walk($child, $root, $chain, $childInsidePropertiesMap, $context, $documentCache);
            }
        }
        unset($child);
    }

    /**
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
        RefResolutionContext $context,
        array &$documentCache,
    ): void {
        if ($ref === '') {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::EmptyRef,
                'Invalid $ref: empty string is not a reference',
                ref: $ref,
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

        if (str_starts_with($ref, 'file://')) {
            // Reject `file://` separately from ordinary path refs so a
            // visual scan of the spec doesn't gloss over it. Path-traversal
            // sandboxing for ordinary paths is intentionally absent — see
            // ExternalRefLoader's class-level docblock for the trust model.
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::FileSchemeNotSupported,
                sprintf(
                    '`file://` $ref is not supported: %s. '
                    . 'Use a relative or absolute filesystem path instead.',
                    $ref,
                ),
                ref: $ref,
            );
        }

        if (str_starts_with($ref, 'http://') || str_starts_with($ref, 'https://')) {
            self::resolveHttpRef($node, $ref, $chain, $context, $documentCache);

            return;
        }

        if (str_starts_with($ref, '#/')) {
            self::resolveInternalRef($node, $ref, $root, $chain, $context, $documentCache);

            return;
        }

        if (str_starts_with($ref, '#')) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::BareFragmentRef,
                sprintf('Invalid $ref: bare fragment %s is not a JSON Pointer (expected "#/..." form)', $ref),
                ref: $ref,
            );
        }

        if ($context->sourceFile === null) {
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

        // RFC 3986 base resolution: when the current document was loaded
        // over HTTP(S), a relative ref resolves against that URL — not
        // against any local filesystem path. Without this branch, dirname()
        // on the URL would produce nonsense like `dirname('https:')` and
        // the user would see a confusing LocalRefNotFound.
        if (str_starts_with($context->sourceFile, 'http://') || str_starts_with($context->sourceFile, 'https://')) {
            $resolvedUrl = self::resolveRelativeUrl($context->sourceFile, $ref);
            self::resolveHttpRef($node, $resolvedUrl, $chain, $context, $documentCache);

            return;
        }

        self::resolveExternalRef($node, $ref, $chain, $context, $documentCache);
    }

    /**
     * Minimal RFC 3986 reference resolution: combine a relative `$ref`
     * with an HTTP(S) base URL. Handles absolute paths (`/foo`),
     * dot-segment normalisation (`./`, `../`), and falls back to the
     * raw ref when it's already an absolute URL.
     *
     * Not a full RFC 3986 implementation — query strings and fragments
     * on the base URL are dropped before joining (matches what spec
     * authors expect when their base is a JSON document URL). For
     * pathological inputs the result is whatever `parse_url` produces;
     * tests pin the common cases.
     */
    private static function resolveRelativeUrl(string $baseUrl, string $relativeRef): string
    {
        if (str_starts_with($relativeRef, 'http://') || str_starts_with($relativeRef, 'https://')) {
            return $relativeRef;
        }

        $baseParts = parse_url($baseUrl);
        if ($baseParts === false || !isset($baseParts['scheme'], $baseParts['host'])) {
            return $relativeRef;
        }

        $scheme = $baseParts['scheme'];
        $host = $baseParts['host'];
        $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
        $userInfo = isset($baseParts['user'])
            ? $baseParts['user'] . (isset($baseParts['pass']) ? ':' . $baseParts['pass'] : '') . '@'
            : '';

        $authority = $userInfo . $host . $port;

        if (str_starts_with($relativeRef, '/')) {
            return sprintf('%s://%s%s', $scheme, $authority, $relativeRef);
        }

        $basePath = $baseParts['path'] ?? '/';
        $baseDir = self::dirnameUrl($basePath);

        $combined = $baseDir . '/' . $relativeRef;
        $normalised = self::normaliseDotSegments($combined);

        return sprintf('%s://%s%s', $scheme, $authority, $normalised);
    }

    private static function dirnameUrl(string $path): string
    {
        $lastSlash = strrpos($path, '/');
        if ($lastSlash === false || $lastSlash === 0) {
            return '';
        }

        return substr($path, 0, $lastSlash);
    }

    /**
     * Apply RFC 3986 §5.2.4 dot-segment removal. Implemented inline
     * (rather than reaching for a library) because the rules are short
     * and the surface area we hit is narrow: relative `./pet.yaml`,
     * `../shared/error.json`, and combinations. Edge cases like a leading
     * `..` past the root collapse to root, matching browser behaviour.
     */
    private static function normaliseDotSegments(string $path): string
    {
        $segments = explode('/', $path);
        $resolved = [];
        foreach ($segments as $segment) {
            if ($segment === '..') {
                array_pop($resolved);
            } elseif ($segment !== '.' && $segment !== '') {
                $resolved[] = $segment;
            }
        }

        return '/' . implode('/', $resolved);
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
        RefResolutionContext $context,
        array &$documentCache,
    ): void {
        // Canonicalize internal refs against the current document so cycles
        // that span files are detected against per-file pointers, not the
        // raw `#/...` string (which is ambiguous across documents).
        $chainKey = self::canonicalChainKey($context->sourceFile, $ref);

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
        self::walk($target, $root, [...$chain, $chainKey], false, $context, $documentCache);
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
        RefResolutionContext $context,
        array &$documentCache,
    ): void {
        [$pathPart, $fragment, $hadHash] = self::splitRef($ref);

        if ($pathPart === '') {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::EmptyRef,
                sprintf('Invalid $ref: %s has an empty path part', $ref),
                ref: $ref,
            );
        }

        // A trailing `#` with nothing after it (`./pet.yaml#`) is
        // ambiguous. Reject it explicitly so the author makes the
        // intent clear: drop the `#` for whole-file, or write `#/...`
        // for a JSON Pointer.
        if ($hadHash && $fragment === '') {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::BareFragmentRef,
                sprintf('Invalid $ref: %s has an empty fragment after `#`', $ref),
                ref: $ref,
            );
        }

        // sourceFile non-null asserted by the caller (resolveRef).
        /** @var string $sourceFile */
        $sourceFile = $context->sourceFile;
        $document = ExternalRefLoader::loadDocument($pathPart, $sourceFile, $documentCache);

        self::descendIntoLoadedDocument(
            $node,
            $ref,
            $fragment,
            $chain,
            $document->canonicalIdentifier,
            $document->decoded,
            $context->withSourceFile($document->canonicalIdentifier),
            $documentCache,
        );
    }

    /**
     * @param array<int|string, mixed> $node
     * @param list<string> $chain
     * @param array<string, array<string, mixed>> $documentCache
     */
    private static function resolveHttpRef(
        array &$node,
        string $ref,
        array $chain,
        RefResolutionContext $context,
        array &$documentCache,
    ): void {
        if (!$context->allowRemoteRefs) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::RemoteRefDisallowed,
                sprintf(
                    'HTTP(S) $ref is disallowed: %s. '
                    . 'Pass allowRemoteRefs: true to OpenApiSpecLoader::configure() to enable it.',
                    $ref,
                ),
                ref: $ref,
            );
        }

        if ($context->httpClient === null || $context->requestFactory === null) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::HttpClientNotConfigured,
                sprintf(
                    'HTTP $ref %s requires a PSR-18 ClientInterface and PSR-17 '
                    . 'RequestFactoryInterface. Pass them to OpenApiSpecLoader::configure().',
                    $ref,
                ),
                ref: $ref,
            );
        }

        [$urlPart, $fragment, $hadHash] = self::splitRef($ref);

        if ($urlPart === '') {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::EmptyRef,
                sprintf('Invalid $ref: %s has an empty URL part', $ref),
                ref: $ref,
            );
        }

        if ($hadHash && $fragment === '') {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::BareFragmentRef,
                sprintf('Invalid $ref: %s has an empty fragment after `#`', $ref),
                ref: $ref,
            );
        }

        try {
            $document = HttpRefLoader::loadDocument(
                $urlPart,
                $context->httpClient,
                $context->requestFactory,
                $documentCache,
            );
        } catch (InvalidOpenApiSpecException $e) {
            // Re-wrap remote-fetch failures with the resolution chain so
            // a failure 4 hops deep into a $ref tree shows the path that
            // got us there, not just the leaf URL.
            if ($e->reason === InvalidOpenApiSpecReason::RemoteRefFetchFailed && $chain !== []) {
                throw new InvalidOpenApiSpecException(
                    $e->reason,
                    sprintf('%s (via $ref chain: %s)', $e->getMessage(), implode(' -> ', $chain)),
                    ref: $e->ref,
                    previous: $e,
                );
            }

            throw $e;
        }

        self::descendIntoLoadedDocument(
            $node,
            $ref,
            $fragment,
            $chain,
            $document->canonicalIdentifier,
            $document->decoded,
            $context->withSourceFile($document->canonicalIdentifier),
            $documentCache,
        );
    }

    /**
     * Shared post-load step for both filesystem and HTTP external refs.
     * Performs cycle detection, fragment lookup, type guard, and recursion
     * with the source-file context shifted to the loaded document.
     *
     * @param array<int|string, mixed> $node
     * @param list<string> $chain
     * @param array<string, mixed> $newRoot
     * @param array<string, array<string, mixed>> $documentCache
     */
    private static function descendIntoLoadedDocument(
        array &$node,
        string $ref,
        string $fragment,
        array $chain,
        string $absoluteUri,
        array $newRoot,
        RefResolutionContext $context,
        array &$documentCache,
    ): void {
        if ($fragment !== '') {
            $internalRef = '#' . $fragment;
            $chainKey = self::canonicalChainKey($absoluteUri, $internalRef);

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
                    sprintf('Unresolvable $ref: fragment %s not found in %s', $fragment, $absoluteUri),
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

            self::walk($target, $newRoot, [...$chain, $chainKey], false, $context, $documentCache);
            $node = $target;

            return;
        }

        // Whole-file/URL ref: chain key is the canonical absolute identifier;
        // the document root replaces the node, and walking continues against
        // that root.
        $chainKey = $absoluteUri;
        if (in_array($chainKey, $chain, true)) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::CircularRef,
                sprintf('Circular $ref detected: %s', implode(' -> ', [...$chain, $chainKey])),
                ref: $ref,
            );
        }

        $target = $newRoot;
        self::walk($target, $newRoot, [...$chain, $chainKey], false, $context, $documentCache);
        $node = $target;
    }

    /**
     * Split a `$ref` like `./schemas/pet.yaml#/components/schemas/Pet`
     * into path part, fragment (without the leading `#`), and a flag
     * marking whether `#` was present at all. Callers need the flag to
     * distinguish `./pet.yaml` (no `#`, whole-file) from `./pet.yaml#`
     * (empty fragment, ambiguous and rejected).
     *
     * @return array{0: string, 1: string, 2: bool}
     */
    private static function splitRef(string $ref): array
    {
        $hashPos = strpos($ref, '#');
        if ($hashPos === false) {
            return [$ref, '', false];
        }

        return [substr($ref, 0, $hashPos), substr($ref, $hashPos + 1), true];
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
