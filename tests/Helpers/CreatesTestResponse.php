<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Helpers;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

trait CreatesTestResponse
{
    /**
     * @param array<string, string> $headers
     *
     * @return TestResponse<Response>
     */
    private function makeTestResponse(string $content, int $statusCode, array $headers = []): TestResponse
    {
        $response = new Response($content, $statusCode, $headers);

        return new TestResponse($response);
    }
}
