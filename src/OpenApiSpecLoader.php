<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use const JSON_THROW_ON_ERROR;

use JsonException;
use Studio\OpenApiContractTesting\Internal\YamlAvailability;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

use function file_exists;
use function file_get_contents;
use function get_debug_type;
use function implode;
use function is_array;
use function json_decode;
use function realpath;
use function rtrim;
use function sprintf;

final class OpenApiSpecLoader
{
    /**
     * Extensions are searched in the order listed; the first hit wins.
     * JSON is first for back-compat with the pre-existing bundle workflow.
     *
     * @var list<string>
     */
    private const SEARCH_EXTENSIONS = ['json', 'yaml', 'yml'];
    private static ?string $basePath = null;

    /** @var string[] */
    private static array $stripPrefixes = [];

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * Configure the spec loader with a base path and optional strip prefixes.
     *
     * @param string[] $stripPrefixes
     */
    public static function configure(string $basePath, array $stripPrefixes = []): void
    {
        self::$basePath = rtrim($basePath, '/');
        self::$stripPrefixes = $stripPrefixes;
    }

    public static function getBasePath(): string
    {
        if (self::$basePath === null) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::BasePathNotConfigured,
                'OpenApiSpecLoader base path not configured. '
                . 'Call OpenApiSpecLoader::configure() or set spec_base_path in PHPUnit extension parameters.',
            );
        }

        return self::$basePath;
    }

    /** @return string[] */
    public static function getStripPrefixes(): array
    {
        return self::$stripPrefixes;
    }

    /** @return array<string, mixed> */
    public static function load(string $specName): array
    {
        if (isset(self::$cache[$specName])) {
            return self::$cache[$specName];
        }

        ['path' => $path, 'extension' => $extension] = self::resolveSpecFile($specName);

        $decoded = match ($extension) {
            'json' => self::decodeJsonSpec($path, $specName),
            'yaml', 'yml' => self::decodeYamlSpec($path, $specName),
            default => throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::UnsupportedExtension,
                "Unsupported spec extension: .{$extension}",
                specName: $specName,
            ),
        };

        // Canonicalize the source path so the resolver's cycle detection
        // sees the same key for this file whether it's reached as the
        // entry spec or as the target of a relative ref from another
        // file. Without this, `basePath` containing `./` or symlinks
        // would yield two distinct chain keys for the same document.
        $canonicalPath = realpath($path);
        if ($canonicalPath === false) {
            // resolveSpecFile() proved the file existed; if realpath
            // fails now it's a races/permissions edge case. Fall back
            // to the raw path so the message still locates it for the
            // operator.
            $canonicalPath = $path;
        }

        try {
            $resolved = OpenApiRefResolver::resolve($decoded, $canonicalPath);
        } catch (InvalidOpenApiSpecException $e) {
            // The resolver is stateless and therefore cannot know which spec
            // produced the throw. Re-wrap once so consumers (e.g. the coverage
            // extension) can surface the spec name in diagnostics without
            // having to correlate against the call site.
            throw $e->withSpecName($specName);
        }
        self::$cache[$specName] = $resolved;

        return $resolved;
    }

    /**
     * Clear only the cached specs, keeping basePath and stripPrefixes intact.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Remove a single spec from the cache.
     */
    public static function evict(string $specName): void
    {
        unset(self::$cache[$specName]);
    }

    public static function reset(): void
    {
        self::$basePath = null;
        self::$stripPrefixes = [];
        self::$cache = [];
        YamlAvailability::reset();
    }

    /** @return array{path: string, extension: string} */
    private static function resolveSpecFile(string $specName): array
    {
        $basePath = self::getBasePath();

        foreach (self::SEARCH_EXTENSIONS as $extension) {
            $candidate = "{$basePath}/{$specName}.{$extension}";
            if (file_exists($candidate)) {
                return ['path' => $candidate, 'extension' => $extension];
            }
        }

        throw new SpecFileNotFoundException(
            $specName,
            $basePath,
            sprintf(
                'OpenAPI bundled spec not found: %s/%s (tried extensions: %s). '
                . "Run 'cd openapi && npm run bundle' to generate a JSON bundle, "
                . 'or place a .yaml / .yml source file alongside.',
                $basePath,
                $specName,
                '.' . implode(', .', self::SEARCH_EXTENSIONS),
            ),
        );
    }

    /** @return array<string, mixed> */
    private static function decodeJsonSpec(string $path, string $specName): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            // I/O failure after resolveSpecFile() already confirmed the file
            // exists is practically always a permissions / concurrent-unlink
            // issue — treat the file as effectively missing.
            throw new SpecFileNotFoundException(
                $specName,
                self::getBasePath(),
                "Failed to read OpenAPI spec: {$path}",
            );
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::MalformedJson,
                "Failed to parse JSON OpenAPI spec: {$path}. {$e->getMessage()}",
                specName: $specName,
                previous: $e,
            );
        }

        if (!is_array($decoded)) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::NonMappingRoot,
                sprintf('JSON OpenAPI spec must decode to a mapping (got %s): %s', get_debug_type($decoded), $path),
                specName: $specName,
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @return array<string, mixed> */
    private static function decodeYamlSpec(string $path, string $specName): array
    {
        if (!YamlAvailability::isAvailable()) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::YamlLibraryMissing,
                'Loading YAML OpenAPI specs requires symfony/yaml. '
                . 'Install it via: composer require --dev symfony/yaml',
                specName: $specName,
            );
        }

        // Yaml::parseFile wraps its own I/O failures in ParseException, so a
        // single catch covers both syntax errors and file-read problems.
        try {
            $decoded = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::MalformedYaml,
                "Failed to parse YAML OpenAPI spec: {$path}. {$e->getMessage()}",
                specName: $specName,
                previous: $e,
            );
        }

        if (!is_array($decoded)) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::NonMappingRoot,
                sprintf('YAML OpenAPI spec must decode to a mapping (got %s): %s', get_debug_type($decoded), $path),
                specName: $specName,
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
