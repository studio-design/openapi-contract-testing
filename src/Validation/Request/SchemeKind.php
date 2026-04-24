<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Request;

/**
 * Classification result produced by {@see SecurityValidator::classifyScheme()}.
 *
 * - `Bearer` / `ApiKey` — well-formed schemes that this library can validate.
 * - `Unsupported`       — well-formed but intentionally deferred for phase 1
 *                         (oauth2, openIdConnect, mutualTLS, http+non-bearer).
 *                         A requirement entry containing any `Unsupported`
 *                         scheme is skipped entirely to avoid false negatives.
 * - `Malformed`         — the spec declaration is broken (missing fields or
 *                         an unknown `type`). Always surfaced as a hard error
 *                         so the spec author is pushed to fix it, even if a
 *                         sibling requirement entry is satisfied.
 */
enum SchemeKind
{
    case Bearer;
    case ApiKey;
    case Unsupported;
    case Malformed;
}
