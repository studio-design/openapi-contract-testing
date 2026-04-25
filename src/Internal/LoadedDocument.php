<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Internal;

/**
 * Result of loading an external `$ref` target — either from the
 * filesystem ({@see ExternalRefLoader}) or via HTTP ({@see HttpRefLoader}).
 *
 * `$canonicalIdentifier` is whatever uniquely names the loaded document:
 * an absolute filesystem path for local refs, an absolute URL for HTTP
 * refs. The resolver uses it for cycle-detection chain keys, so the
 * value must be stable across multiple references to the same target
 * within a single resolution.
 *
 * Replaces the prior `array{absolutePath, decoded}` /
 * `array{absoluteUri, decoded}` shape divergence between the two
 * loaders.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final readonly class LoadedDocument
{
    /** @param array<string, mixed> $decoded */
    public function __construct(
        public string $canonicalIdentifier,
        public array $decoded,
    ) {}
}
