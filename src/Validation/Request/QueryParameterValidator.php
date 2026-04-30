<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Request;

use Studio\OpenApiContractTesting\OpenApiVersion;
use Studio\OpenApiContractTesting\SchemaContext;
use Studio\OpenApiContractTesting\Spec\OpenApiSchemaConverter;
use Studio\OpenApiContractTesting\Validation\Support\ObjectConverter;
use Studio\OpenApiContractTesting\Validation\Support\SchemaValidatorRunner;
use Studio\OpenApiContractTesting\Validation\Support\TypeCoercer;

use function array_key_exists;
use function is_array;

final class QueryParameterValidator
{
    public function __construct(
        private readonly SchemaValidatorRunner $runner,
    ) {}

    /**
     * Validate query parameters declared by the matched operation (or
     * inherited from the path-level `parameters` block).
     *
     * Only `style: form` + `explode: true` (the OpenAPI default for `in: query`)
     * is supported. Repeated keys (`?tags=a&tags=b`) are expected to arrive as
     * PHP arrays from the framework. Other styles (`form`+`explode:false`,
     * `pipeDelimited`, `spaceDelimited`) are out of scope.
     *
     * @param list<array<string, mixed>> $parameters pre-collected merged parameters (path + operation level)
     * @param array<string, mixed> $queryParams
     *
     * @return string[]
     */
    public function validate(
        string $method,
        string $matchedPath,
        array $parameters,
        array $queryParams,
        OpenApiVersion $version,
    ): array {
        $errors = [];

        foreach ($parameters as $param) {
            if (($param['in'] ?? null) !== 'query') {
                continue;
            }

            /** @var string $name */
            $name = $param['name'];
            $required = ($param['required'] ?? false) === true;

            // A required parameter with no schema is a clearly malformed spec — surface it
            // rather than silently passing every request. Optional parameters with no schema
            // have nothing to validate, so we let them through (matches {@see RequestBodyValidator}).
            if (!isset($param['schema']) || !is_array($param['schema'])) {
                if ($required) {
                    $errors[] = "[query.{$name}] required parameter has no schema for {$method} {$matchedPath} — cannot validate.";
                }

                continue;
            }

            /** @var array<string, mixed> $schema */
            $schema = $param['schema'];

            $present = array_key_exists($name, $queryParams) && $queryParams[$name] !== null;
            if (!$present) {
                if ($required) {
                    $errors[] = "[query.{$name}] required query parameter is missing.";
                }

                continue;
            }

            $coerced = TypeCoercer::coerceQuery($queryParams[$name], $schema);
            $jsonSchema = OpenApiSchemaConverter::convert($schema, $version, SchemaContext::Request);

            $schemaObject = ObjectConverter::convert($jsonSchema);
            $dataObject = ObjectConverter::convert($coerced);

            $formatted = $this->runner->validate($schemaObject, $dataObject);
            foreach ($formatted as $path => $messages) {
                $suffix = $path === '/' ? '' : $path;
                foreach ($messages as $message) {
                    $errors[] = "[query.{$name}{$suffix}] {$message}";
                }
            }
        }

        return $errors;
    }
}
