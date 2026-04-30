<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Fuzz;

use InvalidArgumentException;
use Studio\OpenApiContractTesting\HttpMethod;

/**
 * One generated request case produced by {@see OpenApiEndpointExplorer}.
 *
 * `body` is null when the operation declares no `requestBody` (or no
 * `application/json` content; see explorer for the loud-failure cases).
 * `query`, `headers`, and `pathParams` are name → value maps, where the
 * `pathParams` keys are the placeholder *names* extracted from `matchedPath`
 * (e.g. `petId`), not positional. `matchedPath` is the spec template with
 * `{placeholders}` unsubstituted — callers needing a concrete URI substitute
 * `pathParams` into it themselves.
 */
final readonly class ExploredCase
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $pathParams
     *
     * @throws InvalidArgumentException when `matchedPath` is empty — the spec
     *                                  template is the only handle the caller
     *                                  has back to "which operation produced
     *                                  this case", so it must always be set.
     */
    public function __construct(
        public mixed $body,
        public array $query,
        public array $headers,
        public array $pathParams,
        public HttpMethod $method,
        public string $matchedPath,
    ) {
        if ($matchedPath === '') {
            throw new InvalidArgumentException(
                'ExploredCase requires a non-empty matchedPath; an empty template would prevent callers from '
                . 'constructing a request URI.',
            );
        }
    }
}
