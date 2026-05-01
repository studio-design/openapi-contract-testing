<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Request;

/**
 * Immutable result of classifying a security scheme definition.
 *
 * - `$reason` is populated only when `$kind` is {@see SchemeKind::Malformed},
 *   and carries a human-readable explanation that {@see SecurityValidator}
 *   embeds in its error output so the spec author can locate the offending
 *   field.
 * - `$unsupportedTypeLabel` is populated only when `$kind` is
 *   {@see SchemeKind::Unsupported}, and carries the display label
 *   ({@see SecurityValidator::warnIfFirstEncounter()} embeds it in the
 *   silent-pass warning, e.g. `OAuth2`, `OpenID Connect`, `http-basic`).
 */
final readonly class SchemeClassification
{
    public function __construct(
        public SchemeKind $kind,
        public ?string $reason = null,
        public ?string $unsupportedTypeLabel = null,
    ) {}
}
