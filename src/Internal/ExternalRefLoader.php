<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Internal;

use const JSON_THROW_ON_ERROR;
use const PATHINFO_EXTENSION;

use JsonException;
use Studio\OpenApiContractTesting\InvalidOpenApiSpecException;
use Studio\OpenApiContractTesting\InvalidOpenApiSpecReason;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

use function dirname;
use function error_clear_last;
use function error_get_last;
use function file_exists;
use function file_get_contents;
use function get_debug_type;
use function is_array;
use function is_readable;
use function json_decode;
use function pathinfo;
use function realpath;
use function sprintf;
use function str_starts_with;
use function strtolower;

/**
 * Resolves and decodes external `$ref` target documents from the local
 * filesystem. Used by `OpenApiRefResolver` when it encounters a `$ref`
 * whose path part is not an in-document JSON Pointer.
 *
 * Each resolution call passes its own `$documentCache` so the same
 * external file is decoded once even if multiple sibling refs point at
 * different fragments of it. The cache lives only for the duration of
 * one `OpenApiRefResolver::resolve()` call.
 *
 * Path-traversal note: there is no sandbox check on resolved paths. A
 * `$ref` like `'../../etc/passwd.json'` will resolve and read whatever
 * the PHP process can access. Spec authors are trusted; treat the spec
 * directory as a trust boundary in the same way you treat your own
 * source tree. `file://` URLs are rejected separately because they
 * bypass the source-file-relative resolution rules and would surprise
 * a reader scanning paths visually.
 *
 * @internal Not part of the package's public API. Do not call from user code.
 */
final class ExternalRefLoader
{
    private function __construct() {}

    /**
     * Resolve `$refPath` relative to `$sourceFile`, decode the target file,
     * and return the canonical absolute path together with the decoded array.
     *
     * `$refPath` is interpreted as either an absolute filesystem path
     * (when it begins with `/`) or a path relative to the directory of
     * `$sourceFile`. Symlinks are followed via `realpath()`, so two refs
     * that resolve to the same canonical target share a cache entry.
     *
     * @param array<string, array<string, mixed>> $documentCache by-ref cache keyed by absolute path
     *
     * @return array{absolutePath: string, decoded: array<string, mixed>}
     *
     * @throws InvalidOpenApiSpecException when the file cannot be located, decoded, or has an unsupported extension
     */
    public static function loadDocument(string $refPath, string $sourceFile, array &$documentCache): array
    {
        $candidate = str_starts_with($refPath, '/')
            ? $refPath
            : dirname($sourceFile) . '/' . $refPath;

        // realpath() returns false for several distinct conditions
        // (missing file, unreadable parent, broken symlink,
        // open_basedir restriction). Disambiguate via file_exists()
        // against the pre-canonicalized candidate so the user sees
        // the right reason category.
        $absolutePath = realpath($candidate);
        if ($absolutePath === false) {
            if (!file_exists($candidate)) {
                throw new InvalidOpenApiSpecException(
                    InvalidOpenApiSpecReason::LocalRefNotFound,
                    sprintf(
                        'Local $ref target not found: %s (resolved from %s)',
                        $refPath,
                        $sourceFile,
                    ),
                    ref: $refPath,
                );
            }

            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::LocalRefUnreadable,
                sprintf(
                    'Local $ref target exists but cannot be canonicalized: %s '
                    . '(check permissions on the parent directory or symlink chain).',
                    $refPath,
                ),
                ref: $refPath,
            );
        }

        if (isset($documentCache[$absolutePath])) {
            return ['absolutePath' => $absolutePath, 'decoded' => $documentCache[$absolutePath]];
        }

        $extension = strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION));
        if ($extension === '') {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::UnsupportedExtension,
                sprintf('$ref target has no file extension: %s (resolved to %s)', $refPath, $absolutePath),
                ref: $refPath,
            );
        }

        $decoded = match ($extension) {
            'json' => self::decodeJson($absolutePath, $refPath),
            'yaml', 'yml' => self::decodeYaml($absolutePath, $refPath),
            default => throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::UnsupportedExtension,
                sprintf('Unsupported $ref target extension: .%s for %s', $extension, $refPath),
                ref: $refPath,
            ),
        };

        $documentCache[$absolutePath] = $decoded;

        return ['absolutePath' => $absolutePath, 'decoded' => $decoded];
    }

    /** @return array<string, mixed> */
    private static function decodeJson(string $absolutePath, string $refPath): array
    {
        // realpath() proved the file exists and is reachable; a read
        // failure here is a runtime I/O issue (permissions revoked
        // mid-process, disk error, race with unlink). Surface as
        // LocalRefUnreadable rather than the not-found category so
        // operators can tell the difference. error_clear_last/get_last
        // gives us the underlying message without scraping the warning.
        error_clear_last();
        $content = @file_get_contents($absolutePath);
        if ($content === false) {
            $error = error_get_last();
            $detail = $error['message'] ?? 'unknown read error';

            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::LocalRefUnreadable,
                sprintf('Local $ref target unreadable: %s (path: %s, %s)', $refPath, $absolutePath, $detail),
                ref: $refPath,
            );
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::MalformedJson,
                sprintf('Failed to parse JSON $ref target %s: %s', $refPath, $e->getMessage()),
                ref: $refPath,
                previous: $e,
            );
        }

        return self::ensureMappingRoot($decoded, $refPath, $absolutePath);
    }

    /** @return array<string, mixed> */
    private static function decodeYaml(string $absolutePath, string $refPath): array
    {
        if (!YamlAvailability::isAvailable()) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::YamlLibraryMissing,
                'Loading YAML $ref targets requires symfony/yaml. '
                . 'Install it via: composer require --dev symfony/yaml',
                ref: $refPath,
            );
        }

        // is_readable() catches the most common pre-parse I/O failure
        // (permissions revoked between realpath() and Yaml::parseFile).
        // Symfony's Yaml::parseFile rolls every other error class into
        // ParseException, which makes a real I/O failure indistinguishable
        // from a syntax error without this guard.
        if (!is_readable($absolutePath)) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::LocalRefUnreadable,
                sprintf('Local $ref target unreadable: %s (path: %s)', $refPath, $absolutePath),
                ref: $refPath,
            );
        }

        try {
            $decoded = Yaml::parseFile($absolutePath);
        } catch (ParseException $e) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::MalformedYaml,
                sprintf('Failed to parse YAML $ref target %s: %s', $refPath, $e->getMessage()),
                ref: $refPath,
                previous: $e,
            );
        }

        return self::ensureMappingRoot($decoded, $refPath, $absolutePath);
    }

    /** @return array<string, mixed> */
    private static function ensureMappingRoot(mixed $decoded, string $refPath, string $absolutePath): array
    {
        if (!is_array($decoded)) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::NonMappingRoot,
                sprintf(
                    '$ref target must decode to a mapping (got %s): %s (resolved to %s)',
                    get_debug_type($decoded),
                    $refPath,
                    $absolutePath,
                ),
                ref: $refPath,
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
