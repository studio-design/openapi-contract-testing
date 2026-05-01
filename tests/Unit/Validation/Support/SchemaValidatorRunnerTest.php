<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Support;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Validation\Support\ObjectConverter;
use Studio\OpenApiContractTesting\Validation\Support\SchemaValidatorRunner;

use function count;
use function implode;
use function sprintf;

class SchemaValidatorRunnerTest extends TestCase
{
    #[Test]
    public function validate_returns_empty_array_for_valid_data(): void
    {
        $runner = new SchemaValidatorRunner(20);
        $schema = ObjectConverter::convert(['type' => 'integer']);

        $this->assertSame([], $runner->validate($schema, 42));
    }

    #[Test]
    public function validate_returns_formatted_errors_for_invalid_data(): void
    {
        $runner = new SchemaValidatorRunner(20);
        $schema = ObjectConverter::convert(['type' => 'integer']);

        $errors = $runner->validate($schema, 'not-an-int');

        $this->assertNotSame([], $errors);
        $this->assertArrayHasKey('/', $errors);
    }

    #[Test]
    public function validate_returns_nested_pointer_paths(): void
    {
        $runner = new SchemaValidatorRunner(20);
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'properties' => [
                'count' => ['type' => 'integer'],
            ],
            'required' => ['count'],
        ]);
        $data = ObjectConverter::convert(['count' => 'not-an-int']);

        $errors = $runner->validate($schema, $data);

        $this->assertArrayHasKey('/count', $errors);
    }

    #[Test]
    public function constructor_rejects_negative_max_errors(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maxErrors must be 0 (unlimited) or a positive integer, got -1.');

        new SchemaValidatorRunner(-1);
    }

    #[Test]
    public function constructor_accepts_zero_as_unlimited(): void
    {
        $runner = new SchemaValidatorRunner(0);

        $this->assertSame([], $runner->validate(ObjectConverter::convert(['type' => 'string']), 'ok'));
    }

    #[Test]
    public function max_errors_one_stops_at_first_error(): void
    {
        // Pin the `stop_at_first_error: true` branch that the constructor
        // enables when maxErrors === 1. With two independent violations, the
        // capped runner must return exactly one error; the uncapped runner
        // must surface both.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'properties' => [
                'a' => ['type' => 'integer'],
                'b' => ['type' => 'integer'],
            ],
            'required' => ['a', 'b'],
        ]);
        $data = ObjectConverter::convert(['a' => 'not-int', 'b' => 'also-not-int']);

        $cappedErrors = (new SchemaValidatorRunner(1))->validate($schema, $data);
        $uncappedErrors = (new SchemaValidatorRunner(20))->validate($schema, $data);

        $this->assertCount(1, $cappedErrors);
        $this->assertGreaterThan(1, count($uncappedErrors));
    }

    // ============================================================
    // Cascading additionalProperties dedup (issue #159)
    //
    // opis's PropertiesKeyword early-returns on any sub-error and
    // never propagates `$checked` to the validation context. So when
    // `additionalProperties: false` runs afterward, every declared
    // property looks "additional", producing a paired pseudo-error:
    //
    //   [/code]  enum-failure (real)
    //   [/]      Additional object properties are not allowed:
    //              message, code   (← cascade artifact)
    //
    // The runner detects this pattern (a sub-error sitting under the
    // path of a complaining additionalProperties: false error) and
    // strips the cascading names so the user only sees the real
    // signal. See issue #159 for the upstream root cause.
    // ============================================================

    #[Test]
    public function additional_properties_cascade_is_dropped_when_all_listed_props_have_sub_errors(): void
    {
        // Issue #159 minimal repro. Both properties are declared, body
        // is well-shaped except for the `code` enum violation. The
        // additionalProperties cascade should NOT surface — only the
        // real `[/code]` error remains.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'required' => ['message', 'code'],
            'properties' => [
                'message' => ['type' => 'string'],
                'code' => ['type' => 'string', 'enum' => ['allowedCode']],
            ],
            'additionalProperties' => false,
        ]);
        $data = ObjectConverter::convert(['message' => 'oops', 'code' => 'notInEnum']);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $this->assertArrayHasKey('/code', $errors, 'real enum sub-error must remain');
        $this->assertArrayNotHasKey(
            '/',
            $errors,
            sprintf(
                'cascade additionalProperties error must be suppressed; got: %s',
                $this->formatErrors($errors),
            ),
        );
    }

    #[Test]
    public function additional_properties_keeps_real_extras_when_no_cascade(): void
    {
        // Genuine additional property: `extra` is not declared in the
        // schema, so the additionalProperties: false error is the actual
        // contract violation and must surface unchanged.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
            ],
            'additionalProperties' => false,
        ]);
        $data = ObjectConverter::convert(['id' => 1, 'extra' => 'nope']);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $this->assertArrayHasKey('/', $errors);
        $this->assertCount(1, $errors['/']);
        $this->assertStringContainsString('extra', $errors['/'][0]);
    }

    #[Test]
    public function additional_properties_partial_dedup_strips_only_cascade_names(): void
    {
        // Mixed case: `code` fails its enum (cascade artifact) AND
        // `extra` is genuinely undeclared. The kept message should
        // mention `extra` only — `code` is a cascade and must be
        // stripped, but the surrounding additionalProperties signal is
        // still load-bearing for `extra`.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'required' => ['code'],
            'properties' => [
                'code' => ['type' => 'string', 'enum' => ['allowedCode']],
            ],
            'additionalProperties' => false,
        ]);
        $data = ObjectConverter::convert(['code' => 'notInEnum', 'extra' => 'nope']);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $this->assertArrayHasKey('/code', $errors);
        $this->assertArrayHasKey('/', $errors);
        $this->assertCount(1, $errors['/']);
        $this->assertStringContainsString('extra', $errors['/'][0]);
        $this->assertStringNotContainsString(
            'code',
            $errors['/'][0],
            'cascading "code" name must be stripped from the additionalProperties message',
        );
    }

    #[Test]
    public function additional_properties_dedup_handles_nested_object_path(): void
    {
        // The cascade happens at a deeper path than `/`. The dedup
        // logic must use the path prefix correctly — `/wrapper/code`
        // sub-error neutralises the `wrapper.code` mention in the
        // `[/wrapper] additionalProperties` cascade message.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'properties' => [
                'wrapper' => [
                    'type' => 'object',
                    'required' => ['code'],
                    'properties' => [
                        'code' => ['type' => 'string', 'enum' => ['allowedCode']],
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ]);
        $data = ObjectConverter::convert(['wrapper' => ['code' => 'notInEnum']]);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $this->assertArrayHasKey('/wrapper/code', $errors);
        $this->assertArrayNotHasKey(
            '/wrapper',
            $errors,
            sprintf(
                'cascade at /wrapper must be suppressed; got: %s',
                $this->formatErrors($errors),
            ),
        );
    }

    #[Test]
    public function additional_properties_dedup_handles_single_listed_property(): void
    {
        // Single-property cascade: opis emits the message without a
        // comma. The parser must handle that form, otherwise a one-off
        // cascade leaks through.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'required' => ['code'],
            'properties' => [
                'code' => ['type' => 'string', 'enum' => ['allowedCode']],
            ],
            'additionalProperties' => false,
        ]);
        $data = ObjectConverter::convert(['code' => 'notInEnum']);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $this->assertArrayHasKey('/code', $errors);
        $this->assertArrayNotHasKey('/', $errors);
    }

    /**
     * @param array<string, string[]> $errors
     */
    private function formatErrors(array $errors): string
    {
        $lines = [];
        foreach ($errors as $path => $messages) {
            foreach ($messages as $message) {
                $lines[] = sprintf('[%s] %s', $path, $message);
            }
        }

        return implode(' | ', $lines);
    }
}
