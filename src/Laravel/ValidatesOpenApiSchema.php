<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Laravel;

use Illuminate\Testing\TestResponse;
use Studio\OpenApiContractTesting\HttpMethod;
use Studio\OpenApiContractTesting\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\OpenApiResponseValidator;

trait ValidatesOpenApiSchema
{
    protected string $openApiSpec = 'front';

    protected function assertResponseMatchesOpenApiSchema(
        TestResponse $response,
        ?HttpMethod $method = null,
        ?string $path = null,
    ): void {
        $resolvedMethod = $method !== null ? $method->value : app('request')->getMethod();
        $resolvedPath = $path ?? app('request')->getPathInfo();

        $content = $response->getContent();
        if ($content === false) {
            $this->fail('OpenAPI contract testing requires buffered responses, but getContent() returned false (streamed response?).');
        }

        $validator = new OpenApiResponseValidator();
        $result = $validator->validate(
            $this->openApiSpec,
            $resolvedMethod,
            $resolvedPath,
            $response->getStatusCode(),
            $content !== '' ? $response->json() : null,
        );

        if ($result->matchedPath() !== null) {
            OpenApiCoverageTracker::record(
                $this->openApiSpec,
                $resolvedMethod,
                $result->matchedPath(),
            );
        }

        $this->assertTrue(
            $result->isValid(),
            "OpenAPI schema validation failed for {$resolvedMethod} {$resolvedPath} (spec: {$this->openApiSpec}):\n"
            . $result->errorMessage(),
        );
    }
}
