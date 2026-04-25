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
use function file_exists;
use function file_get_contents;
use function get_debug_type;
use function is_array;
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
 * @internal Not part of the package's public API. Do not call from user code.
 */
final class ExternalRefLoader
{
    private function __construct() {}

    /**
     * Resolve `$refPath` relative to `$sourceFile`, decode the target file,
     * and return the canonical absolute path together with the decoded array.
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

        $absolutePath = realpath($candidate);
        if ($absolutePath === false || !file_exists($absolutePath)) {
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

        if (isset($documentCache[$absolutePath])) {
            return ['absolutePath' => $absolutePath, 'decoded' => $documentCache[$absolutePath]];
        }

        $extension = strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION));
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
        $content = file_get_contents($absolutePath);
        if ($content === false) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::LocalRefNotFound,
                sprintf('Local $ref target unreadable: %s (path: %s)', $refPath, $absolutePath),
                ref: $refPath,
            );
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::LocalRefDecodeFailed,
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

        try {
            $decoded = Yaml::parseFile($absolutePath);
        } catch (ParseException $e) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::LocalRefDecodeFailed,
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
