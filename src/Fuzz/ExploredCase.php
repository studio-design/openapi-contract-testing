<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Fuzz;

use InvalidArgumentException;
use Studio\OpenApiContractTesting\HttpMethod;

use function base64_encode;
use function escapeshellarg;
use function http_build_query;
use function implode;
use function json_encode;
use function rawurlencode;
use function sprintf;
use function str_replace;

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
        public ExplorationCaseKind $kind = ExplorationCaseKind::Valid,
        public ?string $targetKeyword = null,
        public ?string $targetPointer = null,
        /** @var list<int> */
        public array $expectedStatusClasses = [],
        public ?int $seed = null,
        public ?int $caseIndex = null,
    ) {
        if ($matchedPath === '') {
            throw new InvalidArgumentException(
                'ExploredCase requires a non-empty matchedPath; an empty template would prevent callers from '
                . 'constructing a request URI.',
            );
        }
    }

    public function withBody(mixed $body): self
    {
        return $this->copy($body, $this->query, $this->headers, $this->pathParams);
    }

    /** @param array<string, mixed> $query */
    public function withQuery(array $query): self
    {
        return $this->copy($this->body, $query, $this->headers, $this->pathParams);
    }

    /** @param array<string, mixed> $headers */
    public function withHeaders(array $headers): self
    {
        return $this->copy($this->body, $this->query, $headers, $this->pathParams);
    }

    /** @param array<string, mixed> $pathParams */
    public function withPathParams(array $pathParams): self
    {
        return $this->copy($this->body, $this->query, $this->headers, $pathParams);
    }

    public function replayToken(): string
    {
        return base64_encode((string) json_encode([
            'method' => $this->method->value,
            'path' => $this->matchedPath,
            'seed' => $this->seed,
            'case' => $this->caseIndex,
            'kind' => $this->kind->value,
            'keyword' => $this->targetKeyword,
            'pointer' => $this->targetPointer,
        ]));
    }

    public function replaySnippet(string $specName): string
    {
        if ($this->kind === ExplorationCaseKind::Invalid) {
            return sprintf(
                "OpenApiEndpointExplorer::exploreInvalid('%s', '%s', '%s', expectedStatusClasses: [%s], cases: %d, seed: %d)",
                $specName,
                $this->method->value,
                $this->matchedPath,
                implode(', ', $this->expectedStatusClasses),
                ($this->caseIndex ?? 0) + 1,
                $this->seed ?? 0,
            );
        }

        return sprintf(
            "OpenApiEndpointExplorer::explore('%s', '%s', '%s', cases: %d, seed: %d)",
            $specName,
            $this->method->value,
            $this->matchedPath,
            ($this->caseIndex ?? 0) + 1,
            $this->seed ?? 0,
        );
    }

    public function curlSnippet(string $baseUrl = ''): string
    {
        $path = $this->matchedPath;
        foreach ($this->pathParams as $name => $value) {
            $path = str_replace('{' . $name . '}', rawurlencode((string) $value), $path);
        }
        $query = $this->query !== [] ? '?' . http_build_query($this->query) : '';
        $command = sprintf('curl -X %s %s', $this->method->value, escapeshellarg($baseUrl . $path . $query));
        foreach ($this->headers as $name => $value) {
            $command .= ' -H ' . escapeshellarg($name . ': ' . (string) $value);
        }
        if ($this->body !== null) {
            $command .= " -H 'Content-Type: application/json' --data " . escapeshellarg((string) json_encode($this->body));
        }

        return $command;
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $pathParams
     */
    private function copy(
        mixed $body,
        array $query,
        array $headers,
        array $pathParams,
    ): self {
        return new self(
            $body,
            $query,
            $headers,
            $pathParams,
            $this->method,
            $this->matchedPath,
            $this->kind,
            $this->targetKeyword,
            $this->targetPointer,
            $this->expectedStatusClasses,
            $this->seed,
            $this->caseIndex,
        );
    }
}
