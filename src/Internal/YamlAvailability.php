<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Internal;

use Symfony\Component\Yaml\Yaml;

use function class_exists;

/**
 * Package-private gate for the `symfony/yaml` availability check.
 *
 * This class exists purely so the production `OpenApiSpecLoader` no longer
 * has to carry a test-only setter on its public API surface. The override
 * state, the real check, and the reset helper all live here instead.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class YamlAvailability
{
    private static ?bool $override = null;

    private function __construct() {}

    public static function isAvailable(): bool
    {
        return self::$override ?? class_exists(Yaml::class);
    }

    /**
     * Force the availability check to return the given value, for tests that
     * need to exercise the missing-dependency error path. Pass null to
     * restore the real `class_exists()` lookup.
     */
    public static function overrideForTesting(?bool $available): void
    {
        self::$override = $available;
    }

    public static function reset(): void
    {
        self::$override = null;
    }
}
