<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Request;

/**
 * Immutable result of classifying a security scheme definition. The `$reason`
 * field is populated only when `$kind` is {@see SchemeKind::Malformed}, and
 * carries a human-readable explanation that {@see SecurityValidator} embeds
 * in its error output so the spec author can locate the offending field.
 */
final readonly class SchemeClassification
{
    public function __construct(
        public SchemeKind $kind,
        public ?string $reason = null,
    ) {}
}
