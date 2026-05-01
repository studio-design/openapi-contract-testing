<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Spec;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Carries the per-resolution state that `OpenApiRefResolver::walk()` needs
 * but which doesn't change per recursion step (source file, HTTP wiring,
 * remote-refs gate). Threading these as discrete parameters got unwieldy
 * once HTTP support added a PSR-18 client + PSR-17 factory + opt-in flag,
 * so they live on this immutable carrier instead.
 *
 * The per-resolution document cache is intentionally NOT held here — it
 * is mutated as files/URLs are loaded, so the resolver still passes it
 * by reference alongside the (immutable) context.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class RefResolutionContext
{
    /**
     * Private constructor — callers go through {@see filesystemOnly()} or
     * {@see withRemoteRefs()} so the pairing invariant
     * "client + factory + flag are all set together, or none of them are"
     * is enforced at construction time. This eliminates the
     * client-without-flag and flag-without-client failure modes that
     * would otherwise need runtime guards.
     */
    private function __construct(
        public readonly ?string $sourceFile,
        public readonly ?ClientInterface $httpClient,
        public readonly ?RequestFactoryInterface $requestFactory,
        public readonly bool $allowRemoteRefs,
    ) {}

    /**
     * A context that can resolve internal `$ref` plus local-filesystem
     * external refs. HTTP refs reject with `RemoteRefDisallowed`.
     */
    public static function filesystemOnly(?string $sourceFile = null): self
    {
        return new self($sourceFile, null, null, false);
    }

    /**
     * A context with HTTP `$ref` resolution enabled. The `$client` /
     * `$factory` pair is required — passing `null` for either is
     * structurally impossible via this factory.
     */
    public static function withRemoteRefs(
        ClientInterface $client,
        RequestFactoryInterface $factory,
        ?string $sourceFile = null,
    ): self {
        return new self($sourceFile, $client, $factory, true);
    }

    /**
     * Return a copy with the source file replaced. Used when the resolver
     * descends into an external document and the relative-path base
     * shifts to that document's directory / URL. All other fields are
     * preserved verbatim — the pairing invariant cannot be invalidated
     * by this method.
     */
    public function withSourceFile(?string $sourceFile): self
    {
        return new self(
            $sourceFile,
            $this->httpClient,
            $this->requestFactory,
            $this->allowRemoteRefs,
        );
    }
}
