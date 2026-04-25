<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

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
 */
final class RefResolutionContext
{
    public function __construct(
        public readonly ?string $sourceFile = null,
        public readonly ?ClientInterface $httpClient = null,
        public readonly ?RequestFactoryInterface $requestFactory = null,
        public readonly bool $allowRemoteRefs = false,
    ) {}

    /**
     * Return a copy with the source file replaced. Used when the resolver
     * descends into an external document and the relative-path base
     * shifts to that document's directory.
     */
    public function withSourceFile(?string $sourceFile): self
    {
        return new self(
            sourceFile: $sourceFile,
            httpClient: $this->httpClient,
            requestFactory: $this->requestFactory,
            allowRemoteRefs: $this->allowRemoteRefs,
        );
    }
}
