<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Support;

use Studio\Gesso\PHPUnit\OpenApiCoverageExtension;
use Studio\Gesso\Spec\OpenApiSchemaConverter;

/**
 * Process-global gate for `discriminator.mapping` enforcement (Issue #262).
 *
 * When enabled, {@see OpenApiSchemaConverter} lowers a schema's
 * `discriminator` + `mapping` into Draft-07 `if` / `then` conditionals so the
 * discriminator value actually steers validation toward a single branch — a
 * body that lies about its type (e.g. `kty=RSA` carrying EC-only fields) fails
 * instead of passing the underlying `oneOf` / `anyOf` union. When disabled,
 * `discriminator` is stripped silently (the historical behaviour, minus the
 * removed `E_USER_WARNING`).
 *
 * Defaults to ON: enforcement is the correct contract-testing behaviour, and
 * `false` is the escape hatch for specs that rely on the loose union semantics
 * or that the lowering cannot fully express (self-referential chains).
 *
 * Static singleton mirroring {@see StrictRequiredPerCallChecker} so the
 * validators can route through a stable static call without changing their
 * SemVer-frozen public constructors. Configured by
 * {@see OpenApiCoverageExtension} (PHPUnit) and by the Laravel
 * `ValidatesOpenApiSchema` trait; read by the body validators when they build
 * a {@see DiscriminatorContext}.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class DiscriminatorEnforcement
{
    private static bool $enabled = true;

    /** Static-only utility — no instances. */
    private function __construct() {}

    /**
     * Set whether discriminator enforcement is active for this process.
     *
     * @internal
     */
    public static function configure(bool $enabled): void
    {
        self::$enabled = $enabled;
    }

    /**
     * @internal Read by the body validators and by tests.
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Reset to the default (ON). Test seam mirroring
     * {@see StrictRequiredPerCallChecker::reset()} so test isolation only
     * needs one teardown call.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$enabled = true;
    }
}
