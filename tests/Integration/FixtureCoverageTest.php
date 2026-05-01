<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Integration;

use const E_USER_WARNING;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\OpenApiRequestValidator;
use Studio\OpenApiContractTesting\OpenApiResponseValidator;
use Studio\OpenApiContractTesting\Spec\OpenApiSchemaConverter;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;

use function implode;
use function restore_error_handler;
use function set_error_handler;
use function str_contains;

/**
 * End-to-end integration tests for the fixture-coverage gaps the v1.0
 * pre-release audit flagged: composition keywords (oneOf / anyOf / not),
 * three forms of `additionalProperties`, eight format keywords (email,
 * uri, ipv4, ipv6, hostname, date, date-time, uuid) plus numeric
 * constraints (multipleOf, uniqueItems, minProperties, maxProperties),
 * OAS 3.1 const lowering, OAS 3.1 internal `$ref`, OAS 3.1
 * readOnly/writeOnly enforcement, wildcard content-type ranges,
 * multi-content-type per status, status-code range keys (5XX / default)
 * on 3.1, and `prefixItems` with actual data.
 *
 * Each pair "valid passes, invalid fails" pins the entire pipeline:
 * spec loader → ref resolver → schema converter → opis validator.
 * Before this file, every one of these features had unit-level coverage
 * in OpenApiSchemaConverterTest with inline schemas only — a regression
 * at any layer between the loader and the converter would not have
 * surfaced.
 */
class FixtureCoverageTest extends TestCase
{
    private OpenApiResponseValidator $responseValidator;
    private OpenApiRequestValidator $requestValidator;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        OpenApiSchemaConverter::resetWarningStateForTesting();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        $this->responseValidator = new OpenApiResponseValidator();
        $this->requestValidator = new OpenApiRequestValidator();
    }

    protected function tearDown(): void
    {
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        OpenApiSchemaConverter::resetWarningStateForTesting();
        parent::tearDown();
    }

    // ============================================================
    // composition.json — oneOf / anyOf / not / additionalProperties
    // ============================================================

    #[Test]
    public function composition_oneof_accepts_each_branch_independently(): void
    {
        // /v1/pets/oneOf carries discriminator.mapping for documentation
        // purposes; the schema converter now warns once per process about it.
        // This test focuses on the union-validates-each-branch behaviour, so
        // the warning is suppressed locally — it's covered explicitly by
        // composition_discriminator_mapping_is_silently_stripped.
        [$cat, $dog] = $this->suppressSchemaWarnings(['discriminator.mapping'], fn() => [
            $this->requestValidator->validate(
                'composition',
                'POST',
                '/v1/pets/oneOf',
                [],
                [],
                ['kind' => 'cat', 'meow' => true],
                'application/json',
            ),
            $this->requestValidator->validate(
                'composition',
                'POST',
                '/v1/pets/oneOf',
                [],
                [],
                ['kind' => 'dog', 'bark' => false],
                'application/json',
            ),
        ]);

        $this->assertTrue($cat->isValid(), 'cat branch: ' . implode(' | ', $cat->errors()));
        $this->assertTrue($dog->isValid(), 'dog branch: ' . implode(' | ', $dog->errors()));
    }

    #[Test]
    public function composition_oneof_rejects_body_matching_no_branch(): void
    {
        // Neither shape: no `kind`, no `meow` / `bark`. discriminator.mapping
        // warning suppressed — see composition_oneof_accepts_each_branch_independently.
        $result = $this->suppressSchemaWarnings(['discriminator.mapping'], fn() => $this->requestValidator->validate(
            'composition',
            'POST',
            '/v1/pets/oneOf',
            [],
            [],
            ['unrelated' => 'value'],
            'application/json',
        ));

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors());
    }

    #[Test]
    public function composition_discriminator_mapping_is_silently_stripped(): void
    {
        // README explicitly documents: `discriminator.mapping` is dropped, the
        // underlying oneOf still validates as a plain union. Pin this so a
        // future change that wires up real mapping support surfaces here as
        // a behaviour change. A body whose `kind` would route to the WRONG
        // branch (Cat) by mapping but matches the OTHER branch's shape (Dog)
        // would FAIL with mapping enforced — but PASS today because mapping
        // is stripped and the body is judged against the oneOf union.
        //
        // The strip now also fires a one-shot E_USER_WARNING; capture it here
        // so the silent-pass-plus-warning contract is pinned end-to-end.
        $captured = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            if ($errno === E_USER_WARNING && str_contains($errstr, 'discriminator.mapping')) {
                $captured = $errstr;

                return true;
            }

            return false;
        });

        try {
            $result = $this->requestValidator->validate(
                'composition',
                'POST',
                '/v1/pets/oneOf',
                [],
                [],
                // kind=dog routes to Dog by mapping; body is a valid Dog.
                // If mapping were enforced and kind said "cat", we would route
                // to Cat and fail. As-is, the body passes the union because
                // it satisfies Dog's branch.
                ['kind' => 'dog', 'bark' => true],
                'application/json',
            );
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($result->isValid(), implode(' | ', $result->errors()));
        $this->assertNotNull($captured, 'discriminator.mapping strip must fire its silent-pass warning');
    }

    #[Test]
    public function composition_anyof_accepts_either_shape(): void
    {
        $byName = $this->requestValidator->validate(
            'composition',
            'POST',
            '/v1/search/anyOf',
            [],
            [],
            ['name' => 'fluffy'],
            'application/json',
        );
        $this->assertTrue($byName->isValid(), implode(' | ', $byName->errors()));

        $byId = $this->requestValidator->validate(
            'composition',
            'POST',
            '/v1/search/anyOf',
            [],
            [],
            ['id' => 42],
            'application/json',
        );
        $this->assertTrue($byId->isValid(), implode(' | ', $byId->errors()));
    }

    #[Test]
    public function composition_anyof_rejects_body_matching_neither(): void
    {
        $result = $this->requestValidator->validate(
            'composition',
            'POST',
            '/v1/search/anyOf',
            [],
            [],
            ['name' => '', 'id' => 0],
            'application/json',
        );

        $this->assertFalse($result->isValid());
    }

    #[Test]
    public function composition_not_keyword_rejects_forbidden_shape(): void
    {
        // `forbidden` key is explicitly disallowed via `not: { required: [forbidden] }`.
        $rejected = $this->requestValidator->validate(
            'composition',
            'POST',
            '/v1/items/not',
            [],
            [],
            ['name' => 'ok', 'forbidden' => true],
            'application/json',
        );
        $this->assertFalse($rejected->isValid());
    }

    #[Test]
    public function composition_not_keyword_accepts_body_without_forbidden_shape(): void
    {
        $accepted = $this->requestValidator->validate(
            'composition',
            'POST',
            '/v1/items/not',
            [],
            [],
            ['name' => 'ok'],
            'application/json',
        );
        $this->assertTrue($accepted->isValid(), implode(' | ', $accepted->errors()));
    }

    #[Test]
    public function composition_additional_properties_false_rejects_extras(): void
    {
        $rejected = $this->responseValidator->validate(
            'composition',
            'GET',
            '/v1/strict',
            200,
            ['id' => 1, 'name' => 'ok', 'extra' => 'nope'],
        );
        $this->assertFalse($rejected->isValid());
    }

    #[Test]
    public function composition_additional_properties_false_accepts_clean_body(): void
    {
        $accepted = $this->responseValidator->validate(
            'composition',
            'GET',
            '/v1/strict',
            200,
            ['id' => 1, 'name' => 'ok'],
        );
        $this->assertTrue($accepted->isValid(), implode(' | ', $accepted->errors()));
    }

    #[Test]
    public function composition_additional_properties_true_accepts_extras(): void
    {
        $result = $this->responseValidator->validate(
            'composition',
            'GET',
            '/v1/loose',
            200,
            ['id' => 1, 'whatever' => 'goes'],
        );
        $this->assertTrue($result->isValid(), implode(' | ', $result->errors()));
    }

    #[Test]
    public function composition_typed_additional_properties_enforces_schema_on_extras(): void
    {
        // Extras must be integers ≥ 0.
        $accepted = $this->responseValidator->validate(
            'composition',
            'GET',
            '/v1/typed-extras',
            200,
            ['id' => 'foo', 'count1' => 1, 'count2' => 7],
        );
        $this->assertTrue($accepted->isValid(), implode(' | ', $accepted->errors()));

        $rejected = $this->responseValidator->validate(
            'composition',
            'GET',
            '/v1/typed-extras',
            200,
            ['id' => 'foo', 'count' => 'not-an-integer'],
        );
        $this->assertFalse($rejected->isValid());
    }

    // ============================================================
    // formats-and-numeric.json
    // ============================================================

    #[Test]
    public function formats_accepts_well_formed_values(): void
    {
        $result = $this->requestValidator->validate(
            'formats-and-numeric',
            'POST',
            '/v1/profile',
            [],
            [],
            [
                'email' => 'jane@example.com',
                'homepage' => 'https://example.com',
                'ip4' => '192.0.2.1',
                'ip6' => '2001:db8::1',
                'host' => 'example.com',
                'birthday' => '1990-04-12',
                'lastSeen' => '2026-04-30T12:34:56Z',
                'userId' => '01234567-89ab-cdef-0123-456789abcdef',
            ],
            'application/json',
        );

        $this->assertTrue($result->isValid(), implode(' | ', $result->errors()));
    }

    #[Test]
    public function formats_rejects_malformed_email(): void
    {
        $result = $this->requestValidator->validate(
            'formats-and-numeric',
            'POST',
            '/v1/profile',
            [],
            [],
            $this->validProfile(['email' => 'not-an-email']),
            'application/json',
        );
        $this->assertFalse($result->isValid());
    }

    #[Test]
    public function formats_rejects_malformed_ipv4(): void
    {
        $result = $this->requestValidator->validate(
            'formats-and-numeric',
            'POST',
            '/v1/profile',
            [],
            [],
            $this->validProfile(['ip4' => '999.999.999.999']),
            'application/json',
        );
        $this->assertFalse($result->isValid());
    }

    #[Test]
    public function formats_rejects_malformed_uuid(): void
    {
        $result = $this->requestValidator->validate(
            'formats-and-numeric',
            'POST',
            '/v1/profile',
            [],
            [],
            $this->validProfile(['userId' => 'definitely-not-a-uuid']),
            'application/json',
        );
        $this->assertFalse($result->isValid());
    }

    #[Test]
    public function formats_rejects_malformed_date(): void
    {
        $result = $this->requestValidator->validate(
            'formats-and-numeric',
            'POST',
            '/v1/profile',
            [],
            [],
            $this->validProfile(['birthday' => '1990-13-99']),
            'application/json',
        );
        $this->assertFalse($result->isValid());
    }

    #[Test]
    public function formats_rejects_malformed_ipv6(): void
    {
        $result = $this->requestValidator->validate(
            'formats-and-numeric',
            'POST',
            '/v1/profile',
            [],
            [],
            $this->validProfile(['ip6' => 'gggg::1']),
            'application/json',
        );
        $this->assertFalse($result->isValid());
    }

    #[Test]
    public function numeric_multiple_of_enforced(): void
    {
        $rejected = $this->requestValidator->validate(
            'formats-and-numeric',
            'POST',
            '/v1/orders',
            [],
            [],
            ['price' => 7, 'tags' => ['a']],
            'application/json',
        );
        $this->assertFalse($rejected->isValid());

        $accepted = $this->requestValidator->validate(
            'formats-and-numeric',
            'POST',
            '/v1/orders',
            [],
            [],
            ['price' => 25, 'tags' => ['a']],
            'application/json',
        );
        $this->assertTrue($accepted->isValid(), implode(' | ', $accepted->errors()));
    }

    #[Test]
    public function numeric_unique_items_enforced(): void
    {
        $rejected = $this->requestValidator->validate(
            'formats-and-numeric',
            'POST',
            '/v1/orders',
            [],
            [],
            ['price' => 25, 'tags' => ['a', 'a']],
            'application/json',
        );
        $this->assertFalse($rejected->isValid());
    }

    #[Test]
    public function numeric_minimum_enforced(): void
    {
        // Order schema declares `price: { type: integer, minimum: 0 }`. A
        // negative value must be rejected — pin the integer/minimum reaches
        // the validator pipeline (no equivalent integration test elsewhere).
        $rejected = $this->requestValidator->validate(
            'formats-and-numeric',
            'POST',
            '/v1/orders',
            [],
            [],
            ['price' => -5, 'tags' => ['a']],
            'application/json',
        );
        $this->assertFalse($rejected->isValid());
    }

    #[Test]
    public function numeric_min_max_properties_enforced(): void
    {
        $tooFew = $this->requestValidator->validate(
            'formats-and-numeric',
            'POST',
            '/v1/orders',
            [],
            [],
            ['price' => 25, 'tags' => ['a'], 'metadata' => (object) []],
            'application/json',
        );
        $this->assertFalse($tooFew->isValid(), 'empty metadata should fail minProperties: 1');

        $tooMany = $this->requestValidator->validate(
            'formats-and-numeric',
            'POST',
            '/v1/orders',
            [],
            [],
            [
                'price' => 25,
                'tags' => ['a'],
                'metadata' => ['k1' => 'a', 'k2' => 'b', 'k3' => 'c', 'k4' => 'd', 'k5' => 'e', 'k6' => 'f'],
            ],
            'application/json',
        );
        $this->assertFalse($tooMany->isValid(), '6 metadata keys should fail maxProperties: 5');
    }

    // ============================================================
    // petstore-3.1.json — new 3.1 surface
    // ============================================================

    #[Test]
    public function v31_const_is_lowered_and_enforced_through_pipeline(): void
    {
        $accepted = $this->responseValidator->validate(
            'petstore-3.1',
            'GET',
            '/v1/v31/version',
            200,
            ['apiVersion' => '1.0'],
        );
        $this->assertTrue($accepted->isValid(), implode(' | ', $accepted->errors()));

        $rejected = $this->responseValidator->validate(
            'petstore-3.1',
            'GET',
            '/v1/v31/version',
            200,
            ['apiVersion' => '2.0'],
        );
        $this->assertFalse($rejected->isValid(), 'pre-fix, const was silently ignored — this would have passed');
    }

    #[Test]
    public function v31_internal_ref_resolves_and_validates(): void
    {
        $result = $this->responseValidator->validate(
            'petstore-3.1',
            'GET',
            '/v1/v31/users/01234567-89ab-cdef-0123-456789abcdef',
            200,
            ['id' => '01234567-89ab-cdef-0123-456789abcdef', 'name' => 'jane'],
        );
        $this->assertTrue($result->isValid(), implode(' | ', $result->errors()));
    }

    #[Test]
    public function v31_response_with_writeonly_property_rejected(): void
    {
        // V31User.password is writeOnly — it must NOT appear in a response body.
        $result = $this->responseValidator->validate(
            'petstore-3.1',
            'POST',
            '/v1/v31/users',
            201,
            [
                'id' => '01234567-89ab-cdef-0123-456789abcdef',
                'name' => 'jane',
                'password' => 's3cret',
            ],
        );

        $this->assertFalse($result->isValid(), 'writeOnly property leaking into response must fail');
    }

    #[Test]
    public function v31_request_with_readonly_property_rejected(): void
    {
        // V31User.id is readOnly — it must NOT appear in a request body.
        $result = $this->requestValidator->validate(
            'petstore-3.1',
            'POST',
            '/v1/v31/users',
            [],
            [],
            [
                'id' => '01234567-89ab-cdef-0123-456789abcdef',
                'name' => 'jane',
            ],
            'application/json',
        );

        $this->assertFalse($result->isValid(), 'readOnly property in request body must fail');
    }

    #[Test]
    public function v31_request_with_writeonly_property_accepted(): void
    {
        // V31User.password is writeOnly — it MUST be allowed in a request
        // body (writeOnly is the dual of readOnly). The asymmetric
        // enforcement should permit this, not reject it.
        $result = $this->requestValidator->validate(
            'petstore-3.1',
            'POST',
            '/v1/v31/users',
            [],
            [],
            [
                'name' => 'jane',
                'password' => 's3cret',
            ],
            'application/json',
        );

        $this->assertTrue($result->isValid(), implode(' | ', $result->errors()));
    }

    #[Test]
    public function v31_application_wildcard_content_routes_through_json_validation(): void
    {
        // Spec declares `application/*` only. Pre-fix, this would have
        // silent-skipped JSON validation; with the wildcard fix, the body is
        // validated against the application/* schema.
        $accepted = $this->responseValidator->validate(
            'petstore-3.1',
            'GET',
            '/v1/v31/wildcard-app',
            200,
            ['ok' => true],
        );
        $this->assertTrue($accepted->isValid(), implode(' | ', $accepted->errors()));

        $rejected = $this->responseValidator->validate(
            'petstore-3.1',
            'GET',
            '/v1/v31/wildcard-app',
            200,
            ['ok' => 'not-a-bool'],
        );
        $this->assertFalse($rejected->isValid(), 'application/* schema must enforce types');
    }

    #[Test]
    public function v31_full_wildcard_content_skipped_for_unknown_response_type(): void
    {
        // `*/*` covers everything; the validator must NOT pretend any body
        // is JSON-validatable just because the spec uses */*. Without an
        // explicit Content-Type, this lands in Skipped.
        $result = $this->responseValidator->validate(
            'petstore-3.1',
            'GET',
            '/v1/v31/wildcard-any',
            200,
            'opaque-body',
        );

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isSkipped(), '*/* must not coerce JSON validation');
    }

    #[Test]
    public function v31_multi_content_type_validates_each_json_against_its_own_schema(): void
    {
        // Per-content-type schema selection (#152): for a JSON-flavoured
        // response Content-Type, schema validation prefers the spec key that
        // exactly matches the response Content-Type before falling back to
        // the first JSON key. The fixture defines DIFFERENT shapes for
        // `application/json` (requires `data`) and `application/problem+json`
        // (requires `title`), so we can prove each is judged against its own
        // schema and not interchangeably as the legacy "first JSON wins"
        // behaviour did.

        // application/json body with `data` (the success shape).
        $jsonResp = $this->responseValidator->validate(
            'petstore-3.1',
            'GET',
            '/v1/v31/multi-content',
            200,
            ['data' => 'hello'],
            'application/json',
        );
        $this->assertTrue($jsonResp->isValid(), implode(' | ', $jsonResp->errors()));

        // application/problem+json body with `title` (the problem shape).
        // Under the legacy first-JSON-wins behaviour this body would have
        // failed against the application/json schema (missing `data`).
        $problemResp = $this->responseValidator->validate(
            'petstore-3.1',
            'GET',
            '/v1/v31/multi-content',
            200,
            ['title' => 'Not Found'],
            'application/problem+json',
        );
        $this->assertTrue($problemResp->isValid(), implode(' | ', $problemResp->errors()));

        // application/problem+json body that DOES NOT match the +json schema
        // (it has `data`, which is the application/json shape). Under the
        // legacy behaviour this would have silently passed; per-content-type
        // selection correctly fails it for missing `title`.
        $wrongShape = $this->responseValidator->validate(
            'petstore-3.1',
            'GET',
            '/v1/v31/multi-content',
            200,
            ['data' => 'hello'],
            'application/problem+json',
        );
        $this->assertFalse($wrongShape->isValid(), 'wrong shape under +json must fail');
        $this->assertStringContainsString('title', $wrongShape->errorMessage());

        // Non-JSON branch: text/plain is presence-only, must pass without
        // schema validation against the JSON schemas.
        $textResp = $this->responseValidator->validate(
            'petstore-3.1',
            'GET',
            '/v1/v31/multi-content',
            200,
            'plain text body',
            'text/plain',
        );
        $this->assertTrue($textResp->isValid(), 'text/plain is presence-only, must pass');
    }

    #[Test]
    public function v31_multi_content_type_falls_back_to_first_json_when_no_exact_match(): void
    {
        // When the response Content-Type is JSON-flavoured but does NOT
        // exactly match any spec key (e.g. `application/vnd.example.v1+json`
        // when the spec only declares `application/json` and
        // `application/problem+json`), fall back to the first JSON key. This
        // preserves the legacy interchangeable-JSON behaviour for users that
        // rely on it for vendor-specific +json suffixes.
        $resp = $this->responseValidator->validate(
            'petstore-3.1',
            'GET',
            '/v1/v31/multi-content',
            200,
            ['data' => 'hello'],
            'application/vnd.example.v1+json',
        );

        $this->assertTrue($resp->isValid(), implode(' | ', $resp->errors()));
    }

    #[Test]
    public function v31_status_range_5xx_resolves_to_skipped_by_default(): void
    {
        // 503 falls under the spec's `5XX` range key. The default skip-by-status
        // policy still treats every 5xx as skipped, so the validator does not
        // schema-check the body but matches it to 5XX for coverage tracking.
        $result = $this->responseValidator->validate(
            'petstore-3.1',
            'GET',
            '/v1/v31/range-keys',
            503,
            ['status' => 503],
        );

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isSkipped());
    }

    #[Test]
    public function v31_status_default_resolves_for_unspec_status(): void
    {
        $result = $this->responseValidator->validate(
            'petstore-3.1',
            'GET',
            '/v1/v31/range-keys',
            418, // not declared as literal, not 5XX → falls to `default`
            ['message' => 'I am a teapot'],
        );

        $this->assertTrue($result->isValid(), implode(' | ', $result->errors()));
    }

    #[Test]
    public function v31_prefix_items_min_max_items_enforced(): void
    {
        // Spec declares `minItems: 2, maxItems: 2` on the tuple. Verify
        // both bounds are enforced — without these, a regression that
        // dropped them from the converter would be invisible (the
        // type-mismatch test already passes for tuples of any length).
        $tooShort = $this->requestValidator->validate(
            'petstore-3.1',
            'POST',
            '/v1/v31/coords',
            [],
            [],
            ['point' => ['origin']],
            'application/json',
        );
        $this->assertFalse($tooShort->isValid(), 'minItems: 2 must reject 1-element tuple');

        $tooLong = $this->requestValidator->validate(
            'petstore-3.1',
            'POST',
            '/v1/v31/coords',
            [],
            [],
            ['point' => ['origin', 42.5, 'extra']],
            'application/json',
        );
        $this->assertFalse($tooLong->isValid(), 'maxItems: 2 must reject 3-element tuple');
    }

    #[Test]
    public function v31_prefix_items_constraint_reaches_validator(): void
    {
        // Regression canary for the Draft 07 default in SchemaValidatorRunner.
        // The converter lowers OAS 3.1 `prefixItems` to Draft 07 array-form
        // `items` (valid Draft 07 tuple validation). opis defaults to
        // 2020-12 where array-form items is invalid, so this test fails with
        // `InvalidKeywordException: items must contain a valid json schema`
        // if anyone reverts SchemaValidatorRunner::__construct's
        // `parser()->setDefaultDraftVersion('07')` call.
        $accepted = $this->requestValidator->validate(
            'petstore-3.1',
            'POST',
            '/v1/v31/coords',
            [],
            [],
            ['point' => ['origin', 42.5]],
            'application/json',
        );
        $this->assertTrue($accepted->isValid(), implode(' | ', $accepted->errors()));

        $rejected = $this->requestValidator->validate(
            'petstore-3.1',
            'POST',
            '/v1/v31/coords',
            [],
            [],
            // Tuple positions reversed — element 0 must be string, element 1 a number.
            ['point' => [42.5, 'origin']],
            'application/json',
        );
        $this->assertFalse($rejected->isValid(), 'prefixItems tuple must reject reversed types');
    }

    /**
     * Build a valid Profile body and override one field so a single test
     * can pin one format failure without re-listing every field.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function validProfile(array $overrides = []): array
    {
        // PHP's `+` operator is left-biased: keys present on the LEFT side
        // win over duplicates on the right. Using `$overrides + $defaults`
        // (NOT array_merge) so the caller's overrides take precedence
        // over the default value for the same key. Inverting the order
        // would silently break every override-based test.
        return $overrides + [
            'email' => 'jane@example.com',
            'homepage' => 'https://example.com',
            'ip4' => '192.0.2.1',
            'ip6' => '2001:db8::1',
            'host' => 'example.com',
            'birthday' => '1990-04-12',
            'lastSeen' => '2026-04-30T12:34:56Z',
            'userId' => '01234567-89ab-cdef-0123-456789abcdef',
        ];
    }

    /**
     * Run a callable while absorbing only the converter warnings whose
     * messages match one of the explicitly-allowed substrings. Any other
     * `E_USER_WARNING` falls through (returns `false` from the handler) so
     * unrelated warnings still surface — preventing this helper from
     * silently absorbing future converter warnings the test was not written
     * to cover.
     *
     * Earlier revisions of this helper filtered by the broad `[OpenAPI
     * Schema]` prefix and would have silently absorbed every converter
     * warning emitted in the wrapped call. A code-review audit flagged
     * that as a silent-failure trap; the explicit allowlist keeps the
     * suppression intent-driven and self-documenting at the call site.
     *
     * @param string[] $allowedSubstrings non-empty list of substrings.
     *                                    A warning is absorbed iff its
     *                                    message contains at least one.
     */
    private function suppressSchemaWarnings(array $allowedSubstrings, callable $fn): mixed
    {
        set_error_handler(static function (int $errno, string $errstr) use ($allowedSubstrings): bool {
            if ($errno !== E_USER_WARNING) {
                return false;
            }

            foreach ($allowedSubstrings as $needle) {
                if (str_contains($errstr, $needle)) {
                    return true;
                }
            }

            return false;
        });

        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }
}
