<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Fuzz;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Fuzz\ExploredCase;
use Studio\OpenApiContractTesting\HttpMethod;

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
}
