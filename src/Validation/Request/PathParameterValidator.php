<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Request;

use Studio\OpenApiContractTesting\OpenApiSchemaConverter;
use Studio\OpenApiContractTesting\OpenApiVersion;
use Studio\OpenApiContractTesting\SchemaContext;
use Studio\OpenApiContractTesting\Validation\Support\ObjectConverter;
use Studio\OpenApiContractTesting\Validation\Support\SchemaValidatorRunner;
use Studio\OpenApiContractTesting\Validation\Support\TypeCoercer;

use function array_key_exists;
use function is_array;
use function rawurldecode;

final class PathParameterValidator
{
    public function __construct(
        private readonly SchemaValidatorRunner $runner,
    ) {}

    /**
     * Validate path parameters declared by the matched operation (or
     * inherited from the path-level `parameters` block) against the values
     * extracted by the path matcher.
     *
     * Path parameters are always required in OpenAPI, so a declared `in: path`
     * entry without a `schema` is treated as a hard spec error rather than a
     * silent pass. Percent-encoded segments are decoded via `rawurldecode()`
     * before type coercion / schema validation — the matcher leaves values
     * raw so encoding policy stays in one place.
     *
     * String-valued `format: uuid | date-time | date | email | ...` is
     * delegated to opis/json-schema's built-in FormatResolver (registered on
     * the `string` type by default). Numeric OAS formats (`int32`, `int64`,
     * `float`, `double`) are advisory-only and are not validated.
     *
     * @param list<array<string, mixed>> $parameters pre-collected merged parameters
     * @param array<string, string> $pathVariables values extracted by OpenApiPathMatcher
     *
     * @return string[]
     */
    public function validate(
        string $method,
        string $matchedPath,
        array $parameters,
        array $pathVariables,
        OpenApiVersion $version,
    ): array {
        $errors = [];
        $declared = [];

        foreach ($parameters as $param) {
            if (($param['in'] ?? null) !== 'path') {
                continue;
            }

            /** @var string $name */
            $name = $param['name'];
            $declared[$name] = true;

            // Defensive: every path placeholder in the matched template should have
            // been captured by the regex. A mismatch here means the spec template and
            // the compiled matcher disagree — surface it loudly rather than skipping.
            if (!array_key_exists($name, $pathVariables)) {
                $errors[] = "[path.{$name}] declared in {$method} {$matchedPath} spec but not captured by path matcher.";

                continue;
            }

            if (!isset($param['schema']) || !is_array($param['schema'])) {
                // Path parameters are implicitly required (OpenAPI spec), so a schema-less
                // entry means every value passes — exactly the silent-drift outcome this
                // library exists to prevent.
                $errors[] = "[path.{$name}] parameter has no schema for {$method} {$matchedPath} — cannot validate.";

                continue;
            }

            /** @var array<string, mixed> $schema */
            $schema = $param['schema'];

            $decoded = rawurldecode($pathVariables[$name]);
            $coerced = TypeCoercer::coercePrimitive($decoded, $schema);
            $jsonSchema = OpenApiSchemaConverter::convert($schema, $version, SchemaContext::Request);

            $schemaObject = ObjectConverter::convert($jsonSchema);
            $dataObject = ObjectConverter::convert($coerced);

            $formatted = $this->runner->validate($schemaObject, $dataObject);
            foreach ($formatted as $path => $messages) {
                $suffix = $path === '/' ? '' : $path;
                foreach ($messages as $message) {
                    $errors[] = "[path.{$name}{$suffix}] {$message}";
                }
            }
        }

        // Reverse check: every `{placeholder}` in the URL template MUST be declared
        // as an `in: path` parameter per OpenAPI. A captured-but-not-declared name
        // means the spec author forgot the declaration entirely, which would otherwise
        // let any value pass silently — the drift this library exists to catch.
        foreach ($pathVariables as $name => $_) {
            if (!isset($declared[$name])) {
                $errors[] = "[path.{$name}] placeholder in {$method} {$matchedPath} template is not declared as an 'in: path' parameter — malformed spec (OpenAPI requires every placeholder to be declared).";
            }
        }

        return $errors;
    }
}
