<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Support;

use Studio\OpenApiContractTesting\Spec\OpenApiSchemaConverter;

/**
 * Carries everything {@see OpenApiSchemaConverter} needs to lower a schema's
 * `discriminator` + `mapping` into Draft-07 conditionals (Issue #262):
 *
 *  - the resolved root spec, so mapping pointers (`#/components/schemas/Cat`
 *    or the bare-name shorthand `Cat`) can be looked up — the converter only
 *    receives a media-type fragment and `$ref` is already inlined by load
 *    time, so the subtype schemas referenced by the mapping are otherwise
 *    unreachable;
 *  - the `enforce` gate (sourced from {@see DiscriminatorEnforcement});
 *  - the recursion guard: the set of discriminator *signatures* already being
 *    enforced on the current lowering path. Because `$ref` is eagerly inlined,
 *    a resolved subtype re-contains the base's `discriminator`
 *    (e.g. `RsaJsonWebKey = allOf:[{inlined base}, {required:[n,e]}]`), so
 *    converting a `then` branch would otherwise re-enter the same lowering
 *    forever. A discriminator whose signature is already active is stripped
 *    without re-lowering — the outer branch already enforces it, and skipping
 *    it both terminates the recursion and avoids combinatorial blow-up for
 *    mappings with many values.
 *
 * Non-body call sites (parameter / header validators, the fuzz explorer) pass
 * {@see self::disabled()} — those positions never carry a `discriminator` and
 * have no root to resolve against, so lowering is a no-op there.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final readonly class DiscriminatorContext
{
    /**
     * @param array<string, mixed> $root the resolved root spec, used only for pointer lookups
     * @param array<string, true> $activeSignatures set of discriminator signatures already being enforced on the current path
     */
    public function __construct(
        public array $root = [],
        public bool $enforce = false,
        public array $activeSignatures = [],
    ) {}

    /**
     * Sentinel for call sites that cannot (or should not) enforce: no root to
     * resolve mapping pointers against and enforcement off, so the converter
     * falls back to stripping `discriminator`.
     */
    public static function disabled(): self
    {
        return new self();
    }

    /**
     * Return a copy with `$signature` added to the active set — threaded into
     * the recursive conversion of a lowered `then` branch so a self-referential
     * subtype does not re-open the same discriminator.
     */
    public function withSignature(string $signature): self
    {
        $signatures = $this->activeSignatures;
        $signatures[$signature] = true;

        return new self($this->root, $this->enforce, $signatures);
    }

    /**
     * True when `$signature` is already being enforced higher up the
     * recursion — the signal to strip the re-appearing discriminator instead
     * of lowering it again.
     */
    public function hasSignature(string $signature): bool
    {
        return isset($this->activeSignatures[$signature]);
    }
}
