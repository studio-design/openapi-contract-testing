<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Internal;

use const PATHINFO_EXTENSION;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Studio\OpenApiContractTesting\InvalidOpenApiSpecException;
use Studio\OpenApiContractTesting\InvalidOpenApiSpecReason;

use function pathinfo;
use function preg_split;
use function rtrim;
use function sprintf;
use function str_ends_with;
use function strcspn;
use function strtolower;
use function substr;
use function trim;

/**
 * Resolves HTTP / HTTPS external `$ref` target documents via a
 * user-provided PSR-18 ClientInterface + PSR-17 RequestFactoryInterface.
 *
 * This is opt-in. The resolver only reaches this loader when the caller
 * explicitly passed `allowRemoteRefs: true` to
 * `OpenApiSpecLoader::configure()`. The library does not bundle an HTTP
 * client implementation; users must wire one of Guzzle / Symfony
 * HttpClient / Buzz / etc. themselves.
 *
 * Each resolution call passes its own `$documentCache`, so the same URL
 * is fetched once even if multiple sibling refs target different
 * fragments of it. The cache lives only for the duration of one
 * `OpenApiRefResolver::resolve()` call (no global / cross-call cache —
 * each test gets a fresh fetch).
 *
 * @internal Not part of the package's public API. Do not call from user code.
 */
final class HttpRefLoader
{
    private function __construct() {}

    /**
     * Fetch `$url`, decode the response body, and return the canonical URL
     * together with the decoded array.
     *
     * Format detection prefers the URL's filename extension
     * (`.json` / `.yaml` / `.yml`), falling back to the response's
     * `Content-Type` header. Refs to URLs that have neither cue throw
     * `UnsupportedExtension`.
     *
     * @param array<string, array<string, mixed>> $documentCache by-ref cache keyed by canonical URL
     *
     * @return array{absoluteUri: string, decoded: array<string, mixed>}
     *
     * @throws InvalidOpenApiSpecException when the URL cannot be fetched, decoded, or has no detectable format
     */
    public static function loadDocument(
        string $url,
        ClientInterface $client,
        RequestFactoryInterface $requestFactory,
        array &$documentCache,
    ): array {
        $canonicalUri = self::canonicalizeUri($url);

        if (isset($documentCache[$canonicalUri])) {
            return ['absoluteUri' => $canonicalUri, 'decoded' => $documentCache[$canonicalUri]];
        }

        $request = $requestFactory->createRequest('GET', $canonicalUri);

        try {
            $response = $client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::RemoteRefFetchFailed,
                sprintf('HTTP $ref fetch failed: %s (%s)', $url, $e->getMessage()),
                ref: $url,
                previous: $e,
            );
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::RemoteRefFetchFailed,
                sprintf('HTTP $ref fetch returned status %d: %s', $status, $url),
                ref: $url,
            );
        }

        $body = (string) $response->getBody();

        $format = self::detectFormat($canonicalUri, $response->getHeaderLine('Content-Type'));
        if ($format === null) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::UnsupportedExtension,
                sprintf(
                    'HTTP $ref %s has no detectable format (no .json/.yaml/.yml extension on the URL '
                    . 'and no recognised Content-Type header).',
                    $url,
                ),
                ref: $url,
            );
        }

        $decoded = $format === 'json'
            ? SpecDocumentDecoder::decodeJson($body, $url)
            : SpecDocumentDecoder::decodeYaml($body, $url);

        $documentCache[$canonicalUri] = $decoded;

        return ['absoluteUri' => $canonicalUri, 'decoded' => $decoded];
    }

    /**
     * Light canonicalisation: trim trailing whitespace. Full URI
     * normalisation (case-folding scheme/host, removing default ports,
     * resolving `..`) is intentionally out of scope — spec authors are
     * trusted to write canonical URLs, and over-aggressive normalisation
     * could merge cache entries for URIs that the server distinguishes.
     */
    private static function canonicalizeUri(string $url): string
    {
        return trim($url);
    }

    /**
     * Pick the spec format from the URL's file extension first; fall back
     * to a coarse Content-Type sniff. Returns `'json'`, `'yaml'`, or null
     * when neither cue is conclusive.
     */
    private static function detectFormat(string $url, string $contentType): ?string
    {
        $path = self::stripQueryAndFragment($url);
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'json') {
            return 'json';
        }
        if ($extension === 'yaml' || $extension === 'yml') {
            return 'yaml';
        }

        $type = strtolower(trim($contentType));
        if ($type === '') {
            return null;
        }

        // Drop charset / boundary parameters before the equality check.
        $type = trim((string) (preg_split('/\s*;\s*/', $type)[0] ?? ''));

        // application/json, application/openapi+json, application/problem+json …
        if ($type === 'application/json' || str_ends_with($type, '+json')) {
            return 'json';
        }

        if ($type === 'application/yaml' ||
            $type === 'application/x-yaml' ||
            $type === 'text/yaml' ||
            $type === 'text/x-yaml' ||
            str_ends_with($type, '+yaml')
        ) {
            return 'yaml';
        }

        return null;
    }

    private static function stripQueryAndFragment(string $url): string
    {
        $cut = strcspn($url, '?#');

        return rtrim(substr($url, 0, $cut));
    }
}
