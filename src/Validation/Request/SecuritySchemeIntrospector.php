<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Request;

use function array_key_exists;
use function is_array;
use function is_string;
use function strtolower;

/**
 * Decides whether an endpoint's spec-declared security permits a bearer
 * credential. Used by the Laravel trait that drives auto-injection of a
 * dummy `Authorization: Bearer <token>` header into the request validator's
 * view — the only reason this lookup exists.
 *
 * The classification rules mirror {@see SecurityValidator::classifyScheme()}
 * exactly; keeping them in sync is a hard requirement because a mismatch
 * would cause the trait to inject on endpoints the validator then considers
 * unauthenticated (or vice versa). This class deliberately returns `false`
 * on malformed or unsupported spec entries rather than mirroring
 * SecurityValidator's hard-error surface: the validator is still the
 * source of truth for "is this spec broken" and we do not want two layers
 * producing redundant errors.
 */
final class SecuritySchemeIntrospector
{
    /**
     * Return true if any spec-declared security requirement for the operation
     * names a scheme that is `http` + `bearer`.
     *
     * Returns true even when bearer appears alongside other schemes in an
     * AND-entry (e.g. `bearer + apiKey`). Injecting bearer alone won't satisfy
     * that entry, but it does silence the "Authorization header is missing"
     * noise and leaves only the actionable apiKey error for the user.
     *
     * @param array<string, mixed> $spec full spec root (for
     *                                   `components.securitySchemes` +
     *                                   root-level `security` inheritance)
     * @param array<string, mixed> $operation operation spec (for
     *                                        operation-level `security` override)
     */
    public function endpointAcceptsBearer(array $spec, array $operation): bool
    {
        $security = array_key_exists('security', $operation)
            ? $operation['security']
            : ($spec['security'] ?? null);

        if (!is_array($security) || $security === []) {
            return false;
        }

        $schemes = $spec['components']['securitySchemes'] ?? [];
        if (!is_array($schemes)) {
            return false;
        }

        foreach ($security as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            foreach ($entry as $schemeName => $_scopes) {
                if (!is_string($schemeName)) {
                    continue;
                }

                $definition = $schemes[$schemeName] ?? null;
                if (!is_array($definition)) {
                    continue;
                }

                if ($this->definitionIsBearer($definition)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function definitionIsBearer(array $definition): bool
    {
        $type = $definition['type'] ?? null;
        if ($type !== 'http') {
            return false;
        }

        $scheme = $definition['scheme'] ?? null;
        if (!is_string($scheme)) {
            return false;
        }

        return strtolower($scheme) === 'bearer';
    }
}
