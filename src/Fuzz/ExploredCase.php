<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Fuzz;

/**
 * One generated request case produced by {@see OpenApiEndpointExplorer}.
 *
 * `body` is null when the operation declares no `requestBody` (or no
 * `application/json` content). `query`, `headers`, and `pathParams` are name
 * → value maps. `matchedPath` is the spec path template (with `{placeholders}`
 * unsubstituted) — callers needing a concrete URI should substitute
 * `pathParams` into it themselves.
 */
final readonly class ExploredCase
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $pathParams
     */
    public function __construct(
        public mixed $body,
        public array $query,
        public array $headers,
        public array $pathParams,
        public string $method,
        public string $matchedPath,
    ) {}

    /**
     * Flatten to an array for use with frameworks that take associative
     * arrays directly (Laravel `postJson($url, $body)`, etc.).
     *
     * @return array{
     *     body: mixed,
     *     query: array<string, mixed>,
     *     headers: array<string, mixed>,
     *     pathParams: array<string, mixed>,
     *     method: string,
     *     matchedPath: string,
     * }
     */
    public function toArray(): array
    {
        return [
            'body' => $this->body,
            'query' => $this->query,
            'headers' => $this->headers,
            'pathParams' => $this->pathParams,
            'method' => $this->method,
            'matchedPath' => $this->matchedPath,
        ];
    }
}
