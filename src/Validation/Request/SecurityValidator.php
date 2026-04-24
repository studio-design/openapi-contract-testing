<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Request;

use LogicException;
use Studio\OpenApiContractTesting\Validation\Support\HeaderNormalizer;

use function array_key_exists;
use function array_key_first;
use function get_debug_type;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function preg_match;
use function sprintf;
use function strtolower;

final class SecurityValidator
{
    /**
     * Validate the endpoint's `security` requirement against the incoming
     * request. Supports `http` + `bearer` and `apiKey` (in: header|query|cookie)
     * schemes. OAuth2 / OpenID Connect are out of scope for phase 1 and are
     * treated as unsupported: any requirement entry containing an unsupported
     * scheme is skipped entirely (contributes neither pass nor fail).
     *
     * Resolution: operation-level `security` takes precedence; otherwise the
     * root-level `security` is inherited. `security: []` (empty array) explicitly
     * opts out of all authentication, so it returns `[]` immediately regardless
     * of root-level definitions. Missing `security` on both levels also returns
     * `[]` (no authentication required).
     *
     * OR / AND semantics (per OpenAPI):
     * - Multiple entries in the `security` array → OR (any one satisfied is enough)
     * - Multiple schemes within a single entry object → AND (all must be satisfied)
     *
     * Malformed spec elements (undefined scheme references, scalar entries,
     * missing `type` / `scheme` / `name` / `in` fields) are always surfaced as
     * hard errors, even if another requirement entry is satisfied — a broken
     * security declaration is something the spec author must fix.
     *
     * @param array<string, mixed> $spec full spec root (for `components.securitySchemes` + root-level `security`)
     * @param array<string, mixed> $operation operation spec (for operation-level `security`)
     * @param array<array-key, mixed> $headers caller-supplied request headers
     * @param array<string, mixed> $queryParams parsed query string
     * @param array<string, mixed> $cookies request cookies (for `apiKey` with `in: cookie`)
     *
     * @return string[]
     */
    public function validate(
        string $method,
        string $matchedPath,
        array $spec,
        array $operation,
        array $headers,
        array $queryParams,
        array $cookies,
    ): array {
        $security = array_key_exists('security', $operation)
            ? $operation['security']
            : ($spec['security'] ?? null);

        if ($security === null) {
            return [];
        }

        if (!is_array($security)) {
            return [
                sprintf(
                    '[security] %s %s: operation/root-level `security` must be an array of requirement objects, got %s.',
                    $method,
                    $matchedPath,
                    get_debug_type($security),
                ),
            ];
        }

        if ($security === []) {
            return [];
        }

        $schemes = $spec['components']['securitySchemes'] ?? [];
        // A non-array `components.securitySchemes` is a malformed spec — without
        // this hard error, every scheme reference below would be reported as
        // "undefined scheme … add it under components.securitySchemes", which
        // misdirects the spec author away from the real cause.
        if (!is_array($schemes)) {
            return [
                sprintf(
                    '[security] %s %s: components.securitySchemes must be an object mapping scheme names to definitions, got %s.',
                    $method,
                    $matchedPath,
                    get_debug_type($schemes),
                ),
            ];
        }

        $normalizedHeaders = HeaderNormalizer::normalize($headers);

        $hardErrors = [];
        $failureErrors = [];
        $satisfied = false;

        foreach ($security as $entryIndex => $entry) {
            if (!is_array($entry)) {
                $hardErrors[] = sprintf(
                    '[security] %s %s: security requirement at index %d must be an object mapping scheme names to scope arrays, got %s.',
                    $method,
                    $matchedPath,
                    is_int($entryIndex) ? $entryIndex : 0,
                    get_debug_type($entry),
                );

                continue;
            }

            $entryHasHardError = false;
            $entryHasUnsupported = false;
            /** @var array<string, array{kind: string, def: array<string, mixed>}> $validatable */
            $validatable = [];

            foreach ($entry as $schemeName => $_scopes) {
                if (!is_string($schemeName)) {
                    $hardErrors[] = sprintf(
                        '[security] %s %s: security scheme name must be a string, got %s.',
                        $method,
                        $matchedPath,
                        get_debug_type($schemeName),
                    );
                    $entryHasHardError = true;

                    continue;
                }

                $schemeDef = $schemes[$schemeName] ?? null;
                if (!is_array($schemeDef)) {
                    $hardErrors[] = sprintf(
                        "[security] %s %s: security requirement references undefined scheme '%s' — add it under components.securitySchemes.",
                        $method,
                        $matchedPath,
                        $schemeName,
                    );
                    $entryHasHardError = true;

                    continue;
                }

                $classification = $this->classifyScheme($schemeDef);

                if ($classification['kind'] === 'malformed') {
                    $hardErrors[] = sprintf(
                        "[security] %s %s: security scheme '%s' is malformed: %s",
                        $method,
                        $matchedPath,
                        $schemeName,
                        $classification['reason'],
                    );
                    $entryHasHardError = true;

                    continue;
                }

                if ($classification['kind'] === 'unsupported') {
                    $entryHasUnsupported = true;

                    continue;
                }

                $validatable[$schemeName] = ['kind' => $classification['kind'], 'def' => $schemeDef];
            }

            if ($entryHasHardError) {
                continue;
            }

            if ($entryHasUnsupported) {
                continue;
            }

            $entryFailures = [];
            foreach ($validatable as $schemeName => $info) {
                $schemeErrors = $this->checkSchemeSatisfaction(
                    $info['kind'],
                    $info['def'],
                    $normalizedHeaders,
                    $queryParams,
                    $cookies,
                );
                foreach ($schemeErrors as $schemeError) {
                    $entryFailures[] = sprintf(
                        "[security] %s %s: requirement '%s' not satisfied: %s",
                        $method,
                        $matchedPath,
                        $schemeName,
                        $schemeError,
                    );
                }
            }

            if ($entryFailures === []) {
                $satisfied = true;

                break;
            }

            $failureErrors = [...$failureErrors, ...$entryFailures];
        }

        if ($satisfied) {
            return $hardErrors;
        }

        // If every requirement entry was either skipped (unsupported scheme
        // within) or malformed (already captured in $hardErrors), there is no
        // *validatable* entry that failed. Returning early avoids blocking a
        // test for a spec we fundamentally cannot evaluate (false-negative
        // avoidance for oauth2-only endpoints).
        if ($failureErrors === []) {
            return $hardErrors;
        }

        return [...$hardErrors, ...$failureErrors];
    }

    /**
     * Classify a security scheme definition into one of:
     * - `bearer`      — http + scheme=bearer (validatable)
     * - `apiKey`      — apiKey in header|query|cookie (validatable)
     * - `unsupported` — a spec-allowed type we intentionally defer (oauth2,
     *                   openIdConnect, mutualTLS, or http with a non-bearer
     *                   scheme). Phase 1 skip — false-negative avoidance.
     * - `malformed`   — missing/invalid required fields, or a `type` that is
     *                   not in the OpenAPI-enumerated set. A typo like
     *                   `{"type": "htpp"}` MUST surface as a hard error
     *                   rather than silently skipping — otherwise the
     *                   library would pass every request for that endpoint.
     *
     * @param array<string, mixed> $schemeDef
     *
     * @return array{kind: string, reason?: string}
     */
    private function classifyScheme(array $schemeDef): array
    {
        $type = $schemeDef['type'] ?? null;
        if (!is_string($type) || $type === '') {
            return ['kind' => 'malformed', 'reason' => "missing required 'type' field."];
        }

        if ($type === 'apiKey') {
            $in = $schemeDef['in'] ?? null;
            $name = $schemeDef['name'] ?? null;
            if (!is_string($in) || !is_string($name)) {
                return ['kind' => 'malformed', 'reason' => "apiKey scheme requires string 'in' and 'name' fields."];
            }
            if (!in_array($in, ['header', 'query', 'cookie'], true)) {
                return ['kind' => 'malformed', 'reason' => "apiKey scheme 'in' must be one of header|query|cookie, got '{$in}'."];
            }

            return ['kind' => 'apiKey'];
        }

        if ($type === 'http') {
            $scheme = $schemeDef['scheme'] ?? null;
            if (!is_string($scheme)) {
                return ['kind' => 'malformed', 'reason' => "http scheme requires a string 'scheme' field (e.g. 'bearer', 'basic')."];
            }

            if (strtolower($scheme) === 'bearer') {
                return ['kind' => 'bearer'];
            }

            // http + basic / digest / etc. are well-formed but phase 1 cannot validate them.
            return ['kind' => 'unsupported'];
        }

        if ($type === 'oauth2' || $type === 'openIdConnect' || $type === 'mutualTLS') {
            return ['kind' => 'unsupported'];
        }

        return [
            'kind' => 'malformed',
            'reason' => "unknown type '{$type}' — OpenAPI 3.x enumerates apiKey|http|oauth2|openIdConnect|mutualTLS.",
        ];
    }

    /**
     * Check whether a single (already-classified, well-formed) security scheme
     * is satisfied by the request. Returns an empty list when satisfied, or
     * one or more error strings explaining why not.
     *
     * @param array<string, mixed> $schemeDef
     * @param array<string, mixed> $normalizedHeaders lower-cased header map
     * @param array<string, mixed> $queryParams
     * @param array<string, mixed> $cookies
     *
     * @return string[]
     */
    private function checkSchemeSatisfaction(
        string $kind,
        array $schemeDef,
        array $normalizedHeaders,
        array $queryParams,
        array $cookies,
    ): array {
        if ($kind === 'bearer') {
            $raw = $normalizedHeaders['authorization'] ?? null;
            $value = $this->extractSingleStringValue($raw);
            if ($value === null || $value === '') {
                return ['Authorization header is missing.'];
            }

            // RFC 6750 §2.1: `Bearer <token>`. Scheme name is case-insensitive
            // per RFC 7235 §2.1, so we accept "Bearer" / "bearer" / "BEARER" etc.
            // Require a non-empty token portion; "Bearer" alone or "Bearer " is
            // not a valid credential.
            if (preg_match('/^bearer\s+(\S+)/i', $value) !== 1) {
                return ["Authorization header does not contain a 'Bearer <token>' credential."];
            }

            return [];
        }

        if ($kind === 'apiKey') {
            /** @var string $in */
            $in = $schemeDef['in'];
            /** @var string $name */
            $name = $schemeDef['name'];

            $raw = match ($in) {
                'header' => $normalizedHeaders[strtolower($name)] ?? null,
                'query' => $queryParams[$name] ?? null,
                'cookie' => $cookies[$name] ?? null,
                default => null,
            };

            $value = $this->extractSingleStringValue($raw);
            if ($value === null || $value === '') {
                return [sprintf("api key '%s' is missing from the %s.", $name, $in)];
            }

            return [];
        }

        throw new LogicException("checkSchemeSatisfaction received unexpected kind '{$kind}'.");
    }

    /**
     * Return the first element of an array, the value itself if it's a string,
     * or `null` otherwise (absent, empty array, or non-string scalar like int
     * or bool). No coercion is performed — a non-string first element still
     * returns `null`.
     *
     * Unlike {@see HeaderParameterValidator} (which rejects multi-value arrays
     * as a hard error to force the spec author to pick a canonical value), the
     * security layer silently accepts the first element. Presence of a
     * credential is all the security layer checks, and duplicate headers are
     * a framework-level concern surfaced elsewhere.
     */
    private function extractSingleStringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            if ($value === []) {
                return null;
            }

            $first = $value[array_key_first($value)];

            return is_string($first) ? $first : null;
        }

        return is_string($value) ? $value : null;
    }
}
