<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Request;

/**
 * Immutable result of classifying a security scheme definition.
 *
 * Construct only via the named factories — `bearer()` / `apiKey()` /
 * `malformed()` / `unsupported()` — so the kind-discriminated payload
 * invariant is enforced in code rather than maintained by convention:
 *
 * - `Malformed`   → `$reason` populated, `$unsupportedTypeLabel` null.
 *   `$reason` is a human-readable explanation pinpointing the broken
 *   spec field.
 * - `Unsupported` → `$unsupportedTypeLabel` populated, `$reason` null.
 *   `$unsupportedTypeLabel` is the display label used in the silent-pass
 *   warning (e.g. `OAuth2`, `OpenID Connect`, `Mutual TLS`, `http-basic`,
 *   `http-digest`).
 * - `Bearer` / `ApiKey` → both fields null.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final readonly class SchemeClassification
{
    private function __construct(
        public SchemeKind $kind,
        public ?string $reason = null,
        public ?string $unsupportedTypeLabel = null,
    ) {}

    public static function bearer(): self
    {
        return new self(SchemeKind::Bearer);
    }

    public static function apiKey(): self
    {
        return new self(SchemeKind::ApiKey);
    }

    public static function malformed(string $reason): self
    {
        return new self(SchemeKind::Malformed, reason: $reason);
    }

    public static function unsupported(string $typeLabel): self
    {
        return new self(SchemeKind::Unsupported, unsupportedTypeLabel: $typeLabel);
    }
}
