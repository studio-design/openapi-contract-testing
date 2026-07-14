<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Fuzz;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Fuzz\ExploredCase;
use Studio\Gesso\Fuzz\FailureReducer;
use Studio\Gesso\HttpMethod;

class ExploredCaseTest extends TestCase
{
    #[Test]
    public function rejects_empty_matched_path(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty matchedPath');

        new ExploredCase(
            body: null,
            query: [],
            headers: [],
            pathParams: [],
            method: HttpMethod::GET,
            matchedPath: '',
        );
    }

    #[Test]
    public function exposes_provided_fields(): void
    {
        $case = new ExploredCase(
            body: ['name' => 'Snowy'],
            query: ['dryRun' => true],
            headers: ['X-Trace' => 'abc'],
            pathParams: ['petId' => 7],
            method: HttpMethod::POST,
            matchedPath: '/v1/pets',
        );

        $this->assertSame(['name' => 'Snowy'], $case->body);
        $this->assertSame(['dryRun' => true], $case->query);
        $this->assertSame(['X-Trace' => 'abc'], $case->headers);
        $this->assertSame(['petId' => 7], $case->pathParams);
        $this->assertSame(HttpMethod::POST, $case->method);
        $this->assertSame('/v1/pets', $case->matchedPath);
    }

    #[Test]
    public function renders_php_and_curl_reproduction_output(): void
    {
        $case = new ExploredCase(
            body: ['name' => 'Snowy'],
            query: ['dryRun' => true],
            headers: ['X-Trace' => 'abc'],
            pathParams: ['petId' => 7],
            method: HttpMethod::POST,
            matchedPath: '/v1/pets/{petId}',
            seed: 42,
            caseIndex: 2,
        );

        $this->assertStringContainsString('cases: 3, seed: 42', $case->replaySnippet('petstore'));
        $this->assertStringContainsString("curl -X POST 'https://api.example.test/v1/pets/7?", $case->curlSnippet('https://api.example.test'));
        $this->assertStringContainsString("--data '{\"name\":\"Snowy\"}'", $case->curlSnippet());
    }

    #[Test]
    public function reducer_preserves_the_original_failure_classification(): void
    {
        $case = new ExploredCase(
            body: ['trigger' => true, 'noiseA' => 1, 'noiseB' => 2],
            query: [],
            headers: [],
            pathParams: [],
            method: HttpMethod::POST,
            matchedPath: '/v1/pets',
        );

        $reduced = FailureReducer::reduce(
            $case,
            static fn(ExploredCase $candidate): ?string => ($candidate->body['trigger'] ?? false) === true ? 'status:500' : null,
        );

        $this->assertSame(['trigger' => true], $reduced->body);
    }
}
