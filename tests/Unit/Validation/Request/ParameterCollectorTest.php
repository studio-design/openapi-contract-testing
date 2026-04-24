<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Request;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Validation\Request\ParameterCollector;

class ParameterCollectorTest extends TestCase
{
    #[Test]
    public function collect_merges_path_and_operation_parameters(): void
    {
        $pathSpec = [
            'parameters' => [
                ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
            ],
        ];
        $operation = [
            'parameters' => [
                ['name' => 'format', 'in' => 'query', 'schema' => ['type' => 'string']],
            ],
        ];

        [$parameters, $errors] = ParameterCollector::collect('GET', '/pets/{id}', $pathSpec, $operation);

        $this->assertSame([], $errors);
        $this->assertCount(2, $parameters);
        $this->assertSame('id', $parameters[0]['name']);
        $this->assertSame('format', $parameters[1]['name']);
    }

    #[Test]
    public function collect_has_operation_level_override_path_level_on_same_name_and_in(): void
    {
        $pathSpec = [
            'parameters' => [
                ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'maximum' => 10]],
            ],
        ];
        $operation = [
            'parameters' => [
                ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'maximum' => 100]],
            ],
        ];

        [$parameters, $errors] = ParameterCollector::collect('GET', '/pets', $pathSpec, $operation);

        $this->assertSame([], $errors);
        $this->assertCount(1, $parameters);
        $this->assertSame(100, $parameters[0]['schema']['maximum']);
    }

    #[Test]
    public function collect_flags_malformed_scalar_parameter_entry(): void
    {
        [$parameters, $errors] = ParameterCollector::collect('GET', '/pets', [], ['parameters' => ['oops']]);

        $this->assertSame([], $parameters);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('expected object, got scalar', $errors[0]);
    }

    #[Test]
    public function collect_rejects_reserved_in_header_names(): void
    {
        $operation = [
            'parameters' => [
                ['name' => 'Content-Type', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']],
            ],
        ];

        [$parameters, $errors] = ParameterCollector::collect('POST', '/pets', [], $operation);

        $this->assertSame([], $parameters);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Reserved in:header parameter', $errors[0]);
        $this->assertStringContainsString('§4.7.12.1', $errors[0]);
    }

    #[Test]
    public function collect_does_not_treat_reserved_name_as_reserved_when_not_header(): void
    {
        // A `in: query, name: accept` is syntactically odd but not governed by
        // §4.7.12.1, so it must pass through rather than being silently dropped.
        $operation = [
            'parameters' => [
                ['name' => 'accept', 'in' => 'query', 'schema' => ['type' => 'string']],
            ],
        ];

        [$parameters, $errors] = ParameterCollector::collect('GET', '/pets', [], $operation);

        $this->assertSame([], $errors);
        $this->assertCount(1, $parameters);
    }
}
