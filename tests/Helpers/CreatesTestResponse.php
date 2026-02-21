<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Helpers;

use const CASE_LOWER;

use Illuminate\Testing\TestResponse;

use function array_change_key_case;
use function strtolower;

trait CreatesTestResponse
{
    /**
     * @param array<string, string> $headers
     */
    private function makeTestResponse(string $content, int $statusCode, array $headers = []): TestResponse
    {
        $headerBag = new class ($headers) {
            /** @var array<string, string> */
            private readonly array $headers;

            /** @param array<string, string> $headers */
            public function __construct(array $headers)
            {
                $this->headers = array_change_key_case($headers, CASE_LOWER);
            }

            public function get(string $key, ?string $default = null): ?string
            {
                return $this->headers[strtolower($key)] ?? $default;
            }
        };

        $baseResponse = new class ($content, $statusCode, $headerBag) {
            public readonly object $headers;

            public function __construct(
                private readonly string $content,
                private readonly int $statusCode,
                object $headers,
            ) {
                $this->headers = $headers;
            }

            public function getContent(): string
            {
                return $this->content;
            }

            public function getStatusCode(): int
            {
                return $this->statusCode;
            }
        };

        return new TestResponse($baseResponse);
    }
}
