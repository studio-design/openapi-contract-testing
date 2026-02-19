<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use const JSON_THROW_ON_ERROR;

use RuntimeException;

use function file_exists;
use function file_get_contents;
use function json_decode;
use function rtrim;

final class OpenApiSpecLoader
{
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

        $path = self::getBasePath() . "/{$specName}.json";

        if (!file_exists($path)) {
            throw new RuntimeException(
                "OpenAPI bundled spec not found: {$path}. Run 'cd openapi && npm run bundle' first.",
            );
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Failed to read OpenAPI spec: {$path}");
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        self::$cache[$specName] = $decoded;

        return $decoded;
    }

    public static function reset(): void
    {
        self::$basePath = null;
        self::$stripPrefixes = [];
        self::$cache = [];
    }
}
