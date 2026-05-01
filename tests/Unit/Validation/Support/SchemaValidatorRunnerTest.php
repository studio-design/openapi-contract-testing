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
use function str_contains;

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
    // opis's PropertiesKeyword skips its addCheckedProperties() call
    // whenever any sub-property fails, leaving `$checked` empty in
    // the validation context. So when `additionalProperties: false`
    // runs afterward, every declared property looks "additional",
    // producing a paired pseudo-error:
    //
    //   [/code]  enum-failure (real)
    //   [/]      Additional object properties are not allowed:
    //              message, code   (← cascade artifact)
    //
    // The runner walks the ValidationError tree, reads the raw list
    // of "additional" property names from `args()['properties']`,
    // and filters out names that ARE declared in the schema's
    // `properties` keyword at that path. See issue #159 for the
    // upstream root cause.
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

    #[Test]
    public function additional_properties_partial_dedup_preserves_exact_message_format(): void
    {
        // Tighter than `partial_dedup_strips_only_cascade_names`: we pin the
        // *exact* rewritten message so a future refactor that decorates the
        // line (e.g. "extra (cascade-stripped)") fails this test instead of
        // silently changing the user-visible contract.
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

        $this->assertSame(
            ['Additional object properties are not allowed: extra'],
            $errors['/'] ?? null,
        );
    }

    #[Test]
    public function additional_properties_dedup_preserves_all_real_sub_errors(): void
    {
        // Issue #159's load-bearing invariant: the dedup must NEVER suppress
        // a real validation error. With two simultaneously-failing declared
        // properties, both `[/message]` and `[/code]` must survive while the
        // cascading `[/]` is dropped. A future refactor that conflated path-
        // walking with error-key matching could pass the single-sub-error
        // tests above while losing one of two co-failing sub-errors.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'required' => ['message', 'code'],
            'properties' => [
                'message' => ['type' => 'string'],
                'code' => ['type' => 'string', 'enum' => ['allowedCode']],
            ],
            'additionalProperties' => false,
        ]);
        // Both `message` (wrong type, integer) and `code` (wrong enum) fail.
        $data = ObjectConverter::convert(['message' => 42, 'code' => 'notInEnum']);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $this->assertArrayHasKey('/message', $errors, 'first real sub-error must survive');
        $this->assertArrayHasKey('/code', $errors, 'second real sub-error must survive');
        $this->assertArrayNotHasKey('/', $errors, 'cascade must be dropped');
    }

    #[Test]
    public function additional_properties_dedup_is_no_op_when_root_schema_is_boolean(): void
    {
        // opis accepts `true`/`false` as top-level schemas. The dedup path
        // requires a stdClass root to introspect; the early-return must NOT
        // suppress real errors that come back when the boolean schema rejects
        // every value.
        $errors = (new SchemaValidatorRunner(0))->validate(false, ObjectConverter::convert(['x' => 1]));

        $this->assertNotSame([], $errors, 'real validation error must surface against `false` schema');
    }

    #[Test]
    public function additional_properties_dedup_keeps_message_under_oneof_composition(): void
    {
        // oneOf routes the data through alternate sub-schemas — the cascade's
        // path doesn't resolve through plain `properties.<name>` walking. The
        // safe-degradation contract is "leave the message untouched"; if a
        // future refactor accidentally suppresses oneOf-branch
        // additionalProperties errors, this test catches it.
        $schema = ObjectConverter::convert([
            'oneOf' => [
                [
                    'type' => 'object',
                    'required' => ['kind'],
                    'properties' => [
                        'kind' => ['type' => 'string', 'enum' => ['a']],
                    ],
                    'additionalProperties' => false,
                ],
                [
                    'type' => 'object',
                    'required' => ['kind'],
                    'properties' => [
                        'kind' => ['type' => 'string', 'enum' => ['b']],
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ]);
        // Real undeclared property — both branches must reject.
        $data = ObjectConverter::convert(['kind' => 'a', 'extra' => 'nope']);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $foundExtraReport = false;
        foreach ($errors as $messages) {
            foreach ($messages as $message) {
                if (str_contains($message, 'extra')) {
                    $foundExtraReport = true;

                    break 2;
                }
            }
        }

        $this->assertTrue(
            $foundExtraReport,
            sprintf(
                'oneOf branch must surface its real additional-property error; got: %s',
                $this->formatErrors($errors),
            ),
        );
    }

    #[Test]
    public function additional_properties_dedup_handles_property_name_with_comma(): void
    {
        // Critical regression: the previous string-based implementation used
        // `explode(',', ...)` to parse the rendered message. A real undeclared
        // property literally named `"a,b"` would split into `["a", "b"]`, both
        // would compare-as-declared if the schema declared `a` and `b`, and
        // the cascade would silently swallow the real "a,b" violation.
        //
        // The current implementation reads the raw `args()['properties']`
        // array directly, so `"a,b"` arrives as a single token and is
        // correctly recognised as undeclared.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'properties' => [
                'a' => ['type' => 'string'],
                'b' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ]);
        $data = ObjectConverter::convert(['a' => 'ok', 'b' => 'ok', 'a,b' => 'real-extra']);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $this->assertArrayHasKey('/', $errors);
        $this->assertCount(1, $errors['/']);
        $this->assertStringContainsString(
            'a,b',
            $errors['/'][0],
            'comma-bearing property name must reach the user as the real additional',
        );
    }

    #[Test]
    public function additional_properties_dedup_handles_empty_property_name(): void
    {
        // Critical regression: opis renders `Additional object properties are
        // not allowed: ` (trailing space) when the only undeclared property
        // is the empty-string key. The previous implementation stripped this
        // via `array_filter` on empty strings and dropped the message
        // entirely — a silent loss. The structural implementation reads the
        // empty string directly from `args()['properties']` and treats it as
        // a real (non-declared) value.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'properties' => [
                'a' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ]);
        $data = ObjectConverter::convert(['a' => 'ok', '' => 'real-extra']);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $this->assertArrayHasKey(
            '/',
            $errors,
            'empty-string undeclared property must surface as a real additional violation',
        );
    }

    #[Test]
    public function additional_properties_dedup_handles_property_name_with_leading_whitespace(): void
    {
        // Critical regression: the previous implementation called `trim()` on
        // each parsed token. A real undeclared property named `' code'` (with
        // a leading space) would compare equal to a declared `'code'` after
        // trimming, silently swallowing the real violation. The structural
        // implementation compares strings exactly.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'properties' => [
                'code' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ]);
        $data = ObjectConverter::convert(['code' => 'ok', ' code' => 'real-extra']);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $this->assertArrayHasKey('/', $errors);
        $this->assertCount(1, $errors['/']);
        // We don't assert on the rendered list format here because opis joins
        // the names with `', '` and a leading-space name would render as
        // `' code'` inside the comma list — visually ambiguous but
        // semantically correct. The presence of any error at `/` is the load-
        // bearing assertion.
    }

    // ============================================================
    // Array-boundary cascade dedup (issue #161)
    //
    // The original #159 dedup walked the schema only via
    // `properties.<name>`. When a data path crossed an array
    // boundary (segment is an int index), the walker bailed at
    // `properties.0` and the cascade message at `[/data/0]` /
    // `[/data/0/plan]` survived even though every name listed in
    // those messages was declared in the corresponding `items`
    // schema.
    //
    // The walker now also steps through `items` for int segments:
    // `items` as stdClass (single-schema items, the OAS 3.0 / 3.1
    // common case) and `items` as a list of stdClass (Draft 07
    // tuple-form, also produced by OpenApiSchemaConverter when it
    // lowers OAS 3.1 `prefixItems`).
    // ============================================================

    #[Test]
    public function additional_properties_cascade_is_dropped_through_array_items(): void
    {
        // Issue #161 minimal repro at one array level. The cascade at
        // `/data/0` lists `name, plan` — both declared in items'
        // properties — so the whole message is a cascade artifact and
        // must be dropped. Only the real `format` failure survives.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'required' => ['data'],
            'properties' => [
                'data' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['plan'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'plan' => ['type' => 'string', 'format' => 'date-time'],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ]);
        $data = ObjectConverter::convert([
            'data' => [['name' => 'a', 'plan' => 'NOT-RFC']],
        ]);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $this->assertArrayHasKey('/data/0/plan', $errors, 'real format sub-error must remain');
        $this->assertArrayNotHasKey(
            '/data/0',
            $errors,
            sprintf(
                'array-boundary cascade must be suppressed; got: %s',
                $this->formatErrors($errors),
            ),
        );
    }

    #[Test]
    public function additional_properties_cascade_is_dropped_through_nested_array_object_chain(): void
    {
        // Full issue #161 repro: schema goes properties → items → properties
        // → items-deep-property. A single deep `format` failure should NOT
        // produce a tower of cascade lines at every intermediate object.
        // All three levels of cascade (root, /data/0, /data/0/plan) must
        // collapse to a single real error line at /data/0/plan/ts.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'required' => ['data'],
            'properties' => [
                'data' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['plan'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'plan' => [
                                'type' => 'object',
                                'required' => ['ts'],
                                'properties' => [
                                    'ts' => ['type' => 'string', 'format' => 'date-time'],
                                    'level' => ['type' => 'string'],
                                ],
                                'additionalProperties' => false,
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'additionalProperties' => false,
        ]);
        $data = ObjectConverter::convert([
            'data' => [['name' => 'a', 'plan' => ['ts' => 'NOT-RFC', 'level' => 'pro']]],
        ]);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $this->assertArrayHasKey('/data/0/plan/ts', $errors, 'real format sub-error must remain');
        $this->assertArrayNotHasKey('/data/0/plan', $errors, 'inner-object cascade must drop');
        $this->assertArrayNotHasKey('/data/0', $errors, 'array-element cascade must drop');
        $this->assertArrayNotHasKey('/', $errors, 'root cascade must drop');
    }

    #[Test]
    public function additional_properties_cascade_is_dropped_through_tuple_form_items(): void
    {
        // Draft 07 tuple-form items (also: the lowered shape of OAS 3.1
        // prefixItems via OpenApiSchemaConverter). Each numeric segment
        // must descend into items[<n>], not into a single shared schema.
        // The `code` cascade at `/items/1` must be dropped because every
        // listed name is declared in the per-index sub-schema.
        $schema = ObjectConverter::convert([
            'type' => 'array',
            'items' => [
                ['type' => 'string'],
                [
                    'type' => 'object',
                    'required' => ['code'],
                    'properties' => [
                        'code' => ['type' => 'string', 'enum' => ['allowedCode']],
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ]);
        $data = ObjectConverter::convert(['ok', ['code' => 'notInEnum']]);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $this->assertArrayHasKey('/1/code', $errors, 'real enum sub-error must remain');
        $this->assertArrayNotHasKey(
            '/1',
            $errors,
            sprintf(
                'tuple-form cascade must be suppressed; got: %s',
                $this->formatErrors($errors),
            ),
        );
    }

    #[Test]
    public function additional_properties_dedup_degrades_safely_when_items_is_boolean_schema(): void
    {
        // Walker safe-degradation: when `items` is a boolean schema (opis
        // accepts `items: true` / `items: false`, neither of which we can
        // meaningfully introspect), the walker must bail at the array hop
        // and leave any cascade message at `/data/<n>` untouched. This
        // pins both halves of the contract: (a) the walker does not crash
        // on the unsupported shape, and (b) it does not silently suppress
        // an additionalProperties signal at the boundary it could not
        // resolve. We use `items: false` so each item is rejected, and an
        // additionalProperties error on a parallel object also fires —
        // both must reach the user.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'properties' => [
                'data' => [
                    'type' => 'array',
                    'items' => false,
                ],
            ],
            'additionalProperties' => false,
        ]);
        $data = ObjectConverter::convert(['data' => [1], 'extra' => 'real']);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $foundExtra = false;
        foreach ($errors as $messages) {
            foreach ($messages as $message) {
                if (str_contains($message, 'extra')) {
                    $foundExtra = true;

                    break 2;
                }
            }
        }

        $this->assertTrue(
            $foundExtra,
            sprintf(
                'real undeclared property must reach the user even when the schema crosses an unsupported array shape; got: %s',
                $this->formatErrors($errors),
            ),
        );
    }

    #[Test]
    public function additional_properties_keeps_real_extras_inside_array_items(): void
    {
        // Bidirectional regression: when an item object actually carries
        // an undeclared property, the additionalProperties error at
        // `/data/0` must SURVIVE intact — only declared-name cascade
        // artifacts are filtered, real extras are preserved as-is.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'properties' => [
                'data' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ]);
        $data = ObjectConverter::convert([
            'data' => [['name' => 'a', 'rogue' => 'real-extra']],
        ]);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $this->assertArrayHasKey('/data/0', $errors);
        $this->assertCount(1, $errors['/data/0']);
        $this->assertStringContainsString('rogue', $errors['/data/0'][0]);
        $this->assertStringNotContainsString(
            'name',
            $errors['/data/0'][0],
            'declared name must not appear in the kept additional-properties message',
        );
    }

    #[Test]
    public function additional_properties_dedup_handles_property_name_with_slash(): void
    {
        // Property names containing `/` produce JSON-Pointer-encoded path
        // segments (`a/b` → pointer `/a~1b`) when they appear as data keys.
        // The structural walker uses raw segments from `DataInfo::fullPath()`
        // so the slash-bearing name matches the schema's declaration without
        // any decoding work on our side. The previous implementation walked
        // the pointer string with `explode('/')` and silently failed to
        // resolve such schemas; the message was kept (safe direction), but
        // no actual dedup happened.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'properties' => [
                'a/b' => ['type' => 'string', 'enum' => ['allowed']],
            ],
            'additionalProperties' => false,
        ]);
        $data = ObjectConverter::convert(['a/b' => 'notAllowed']);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        // The enum sub-error fires at the JSON-Pointer-encoded path.
        $this->assertArrayHasKey('/a~1b', $errors, 'enum sub-error must survive at the encoded path');
        $this->assertArrayNotHasKey(
            '/',
            $errors,
            'cascade for the slash-bearing declared name must be dropped',
        );
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
