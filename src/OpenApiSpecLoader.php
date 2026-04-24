<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use const JSON_THROW_ON_ERROR;

use JsonException;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

use function class_exists;
use function file_exists;
use function file_get_contents;
use function get_debug_type;
use function implode;
use function is_array;
use function json_decode;
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
     * Test-only override for the `symfony/yaml` availability check.
     * null means "ask the real class_exists()". true/false forces the answer.
     */
    private static ?bool $yamlAvailableOverride = null;

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
            throw new RuntimeException(
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
            'json' => self::decodeJsonSpec($path),
            'yaml', 'yml' => self::decodeYamlSpec($path),
            default => throw new RuntimeException("Unsupported spec extension: .{$extension}"),
        };

        $resolved = OpenApiRefResolver::resolve($decoded);
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
        self::$yamlAvailableOverride = null;
    }

    /**
     * Force `symfony/yaml` to appear installed / missing, for tests that cover
     * the missing-dependency error path. Pass null to restore the real check.
     *
     * @internal
     */
    public static function overrideYamlAvailabilityForTesting(?bool $available): void
    {
        self::$yamlAvailableOverride = $available;
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

        throw new RuntimeException(sprintf(
            'OpenAPI bundled spec not found: %s/%s (tried extensions: %s). '
            . "Run 'cd openapi && npm run bundle' to generate a JSON bundle, "
            . 'or place a .yaml / .yml source file alongside.',
            $basePath,
            $specName,
            '.' . implode(', .', self::SEARCH_EXTENSIONS),
        ));
    }

    /** @return array<string, mixed> */
    private static function decodeJsonSpec(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Failed to read OpenAPI spec: {$path}");
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                "Failed to parse JSON OpenAPI spec: {$path}. {$e->getMessage()}",
                0,
                $e,
            );
        }

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf(
                'JSON OpenAPI spec must decode to a mapping (got %s): %s',
                get_debug_type($decoded),
                $path,
            ));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @return array<string, mixed> */
    private static function decodeYamlSpec(string $path): array
    {
        if (!self::isYamlLibraryAvailable()) {
            throw new RuntimeException(
                'Loading YAML OpenAPI specs requires symfony/yaml. '
                . 'Install it via: composer require --dev symfony/yaml',
            );
        }

        // Yaml::parseFile wraps its own I/O failures in ParseException, so a
        // single catch covers both syntax errors and file-read problems.
        try {
            $decoded = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new RuntimeException(
                "Failed to parse YAML OpenAPI spec: {$path}. {$e->getMessage()}",
                0,
                $e,
            );
        }

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf(
                'YAML OpenAPI spec must decode to a mapping (got %s): %s',
                get_debug_type($decoded),
                $path,
            ));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function isYamlLibraryAvailable(): bool
    {
        return self::$yamlAvailableOverride ?? class_exists(Yaml::class);
    }
}
