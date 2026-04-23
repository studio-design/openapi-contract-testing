# OpenAPI Contract Testing for PHPUnit

[![CI](https://github.com/studio-design/openapi-contract-testing/actions/workflows/ci.yml/badge.svg)](https://github.com/studio-design/openapi-contract-testing/actions/workflows/ci.yml)
[![Latest Stable Version](https://poser.pugx.org/studio-design/openapi-contract-testing/v)](https://packagist.org/packages/studio-design/openapi-contract-testing)
[![License](https://poser.pugx.org/studio-design/openapi-contract-testing/license)](https://packagist.org/packages/studio-design/openapi-contract-testing)

Framework-agnostic OpenAPI 3.0/3.1 contract testing for PHPUnit **with endpoint coverage tracking**.

Validate your API responses against your OpenAPI specification during testing, and get a coverage report showing which endpoints have been tested.

## Features

- **OpenAPI 3.0 & 3.1 support** — Automatic version detection from the `openapi` field
- **Response validation** — Validates response bodies against JSON Schema (Draft 07 via opis/json-schema). Supports `application/json` and any `+json` content type (e.g., `application/problem+json`)
- **Content negotiation** — Accepts the actual response `Content-Type` to handle mixed-content specs. Non-JSON responses (e.g., `text/html`, `application/xml`) are verified for spec presence without body validation; JSON-compatible responses are fully schema-validated
- **Skip-by-status-code** — Configurable regex list of status codes whose bodies are not validated (default: every `5xx`), reflecting the common convention of not documenting production error responses in the spec. Also available per-request via the fluent `skipResponseCode()` API
- **Endpoint coverage tracking** — Unique PHPUnit extension that reports which spec endpoints are covered by tests
- **Path matching** — Handles parameterized paths (`/pets/{petId}`) with configurable prefix stripping
- **Laravel adapter** — Optional trait for seamless integration with Laravel's `TestResponse`
- **Zero runtime overhead** — Only used in test suites

## Requirements

- PHP 8.2+
- PHPUnit 11, 12, or 13
- [Redocly CLI](https://redocly.com/docs/cli/) (recommended for `$ref` resolution / bundling)

## Installation

```bash
composer require --dev studio-design/openapi-contract-testing
```

## Setup

### 1. Bundle your OpenAPI spec

This package expects a **bundled** (all `$ref`s resolved) JSON spec file. Use [Redocly CLI](https://redocly.com/docs/cli/commands/bundle/) to bundle:

```bash
npx @redocly/cli bundle openapi/root.yaml --dereferenced -o openapi/bundled/front.json
```

> **Important:** The `--dereferenced` flag is required. Without it, `$ref` pointers (e.g., `#/components/schemas/...`) are preserved in the output, causing `UnresolvedReferenceException` at validation time. The underlying JSON Schema validator (`opis/json-schema`) does not resolve OpenAPI `$ref` references.

### 2. Configure PHPUnit extension

Add the coverage extension to your `phpunit.xml`:

```xml
<extensions>
    <bootstrap class="Studio\OpenApiContractTesting\PHPUnit\OpenApiCoverageExtension">
        <parameter name="spec_base_path" value="openapi/bundled"/>
        <parameter name="strip_prefixes" value="/api"/>
        <parameter name="specs" value="front,admin"/>
    </bootstrap>
</extensions>
```

| Parameter | Required | Default | Description |
|---|---|---|---|
| `spec_base_path` | Yes* | — | Path to bundled spec directory (relative paths resolve from `getcwd()`) |
| `strip_prefixes` | No | `[]` | Comma-separated prefixes to strip from request paths (e.g., `/api`) |
| `specs` | No | `front` | Comma-separated spec names for coverage tracking |
| `output_file` | No | — | File path to write Markdown coverage report (relative paths resolve from `getcwd()`) |
| `console_output` | No | `default` | Console output mode: `default`, `all`, or `uncovered_only` (overridden by `OPENAPI_CONSOLE_OUTPUT` env var) |

*Not required if you call `OpenApiSpecLoader::configure()` manually.

### 3. Use in tests

#### With Laravel (recommended)

Publish the config file:

```bash
php artisan vendor:publish --tag=openapi-contract-testing
```

This creates `config/openapi-contract-testing.php`:

```php
return [
    'default_spec' => '', // e.g., 'front'

    // Maximum number of validation errors to report per response.
    // 0 = unlimited (reports all errors).
    'max_errors' => 20,

    // Automatically validate every TestResponse produced by Laravel HTTP
    // helpers (get(), post(), etc.) against the OpenAPI spec. Defaults to
    // false for backward compatibility.
    'auto_assert' => false,

    // Regex patterns (without delimiters or anchors) matched against the
    // response status code. Matching codes short-circuit body validation —
    // the test passes and the endpoint is still recorded as covered.
    // Defaults to skipping every 5xx. Set to [] to validate every code.
    'skip_response_codes' => ['5\d\d'],
];
```

Set `default_spec` to your spec name, then use the trait — no per-class override needed:

```php
use Studio\OpenApiContractTesting\Laravel\ValidatesOpenApiSchema;

class GetPetsTest extends TestCase
{
    use ValidatesOpenApiSchema;

    public function test_list_pets(): void
    {
        $response = $this->get('/api/v1/pets');
        $response->assertOk();
        $this->assertResponseMatchesOpenApiSchema($response);
    }
}
```

To use a different spec for a specific test class, add the `#[OpenApiSpec]` attribute:

```php
use Studio\OpenApiContractTesting\OpenApiSpec;
use Studio\OpenApiContractTesting\Laravel\ValidatesOpenApiSchema;

#[OpenApiSpec('admin')]
class AdminGetUsersTest extends TestCase
{
    use ValidatesOpenApiSchema;

    // All tests in this class use the 'admin' spec
}
```

You can also specify the spec per test method. Method-level attributes take priority over class-level:

```php
#[OpenApiSpec('front')]
class MixedApiTest extends TestCase
{
    use ValidatesOpenApiSchema;

    public function test_front_endpoint(): void
    {
        // Uses 'front' from class-level attribute
    }

    #[OpenApiSpec('admin')]
    public function test_admin_endpoint(): void
    {
        // Uses 'admin' from method-level attribute (overrides class)
    }
}
```

Resolution priority (highest to lowest):

1. Method-level `#[OpenApiSpec]` attribute
2. Class-level `#[OpenApiSpec]` attribute
3. `openApiSpec()` method override
4. `config('openapi-contract-testing.default_spec')`

> **Note:** You can still override `openApiSpec()` as before — it remains fully backward-compatible.

#### Framework-agnostic

You can use the `#[OpenApiSpec]` attribute with the `OpenApiSpecResolver` trait in any PHPUnit test:

```php
use Studio\OpenApiContractTesting\OpenApiSpec;
use Studio\OpenApiContractTesting\OpenApiSpecResolver;
use Studio\OpenApiContractTesting\OpenApiResponseValidator;

#[OpenApiSpec('front')]
class GetPetsTest extends TestCase
{
    use OpenApiSpecResolver;

    public function test_list_pets(): void
    {
        $specName = $this->resolveOpenApiSpec(); // 'front'
        $validator = new OpenApiResponseValidator();
        $result = $validator->validate(
            specName: $specName,
            method: 'GET',
            requestPath: '/api/v1/pets',
            statusCode: 200,
            responseBody: $decodedJsonBody,
            responseContentType: 'application/json',
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }
}
```

Or without the attribute, pass the spec name directly:

```php
use Studio\OpenApiContractTesting\OpenApiResponseValidator;
use Studio\OpenApiContractTesting\OpenApiSpecLoader;

// Configure once (e.g., in bootstrap)
OpenApiSpecLoader::configure(__DIR__ . '/openapi/bundled', ['/api']);

// In your test
$validator = new OpenApiResponseValidator();
$result = $validator->validate(
    specName: 'front',
    method: 'GET',
    requestPath: '/api/v1/pets',
    statusCode: 200,
    responseBody: $decodedJsonBody,
    responseContentType: 'application/json', // optional: enables content negotiation
);

$this->assertTrue($result->isValid(), $result->errorMessage());
```

#### Controlling the number of validation errors

By default, up to **20** validation errors are reported per response. You can change this via the constructor:

```php
// Report up to 5 errors
$validator = new OpenApiResponseValidator(maxErrors: 5);

// Report all errors (unlimited)
$validator = new OpenApiResponseValidator(maxErrors: 0);

// Stop at first error (pre-v0.x default)
$validator = new OpenApiResponseValidator(maxErrors: 1);
```

For Laravel, set the `max_errors` key in `config/openapi-contract-testing.php`.

#### Skipping responses by status code

Production error responses (typically `5xx`) are often deliberately left out of the OpenAPI spec. Without special handling, a test that hits a `500` would fail twice: once from the underlying bug, and again from "Status code 500 not defined". To avoid that noise, every `5xx` response is **skipped by default** — body validation is not performed, the assertion passes, and the endpoint is still recorded as covered.

Override via `skip_response_codes` in `config/openapi-contract-testing.php`:

```php
return [
    // Default — skip all 5xx
    'skip_response_codes' => ['5\d\d'],

    // Widen to also skip all 4xx
    'skip_response_codes' => ['4\d\d', '5\d\d'],

    // Disable entirely — validate every status code
    'skip_response_codes' => [],
];
```

Or pass directly to `OpenApiResponseValidator`:

```php
$validator = new OpenApiResponseValidator(skipResponseCodes: ['5\d\d']);
```

Notes:

- Patterns are regex strings **without** `/` delimiters or `^$` anchors; they are anchored automatically, so `5\d\d` matches exactly `500`–`599` (not `5000`).
- The skip check sits **between** the "path / method not in spec" checks and the "status code not defined" / schema-validation checks. A skipped code therefore suppresses both status-code failure modes (undocumented code AND body mismatch for a documented code), but typos in the request path or method still fail loudly.
- Skipped endpoints count as covered — the endpoint was exercised, just not schema-validated. Coverage semantics here match how non-JSON content types and schema-less `204` responses are handled, but `OpenApiValidationResult::isSkipped()` returns `true` **only** for status-code skips; the other no-body-validation branches still return a plain `success()`.
- `OpenApiValidationResult::isSkipped()` is exposed for callers who want to distinguish a skip from a genuine success. `skipReason()` identifies the matched pattern.
- **Observability trade-off**: a real regression that causes an unrelated `500` will not fail this assertion. Keep your HTTP-level assertions (`$response->assertOk()`, status-code expectations in the test) alongside the contract check so a stray 5xx still surfaces — the contract assertion alone is not a substitute for status-code assertions on happy paths.

#### Auto-assert every response

Forgetting `$this->assertResponseMatchesOpenApiSchema($response)` in a test means the contract is silently unchecked. Enable `auto_assert` to validate every response produced by Laravel's HTTP helpers automatically — just include the trait:

```php
// config/openapi-contract-testing.php
return [
    'default_spec' => 'front',
    'auto_assert'  => true,
];
```

```php
use Studio\OpenApiContractTesting\Laravel\ValidatesOpenApiSchema;

class GetPetsTest extends TestCase
{
    use ValidatesOpenApiSchema;

    public function test_list_pets(): void
    {
        // Contract is checked automatically — no explicit assert call needed.
        $this->get('/api/v1/pets')->assertOk();
    }
}
```

Notes:

- Defaults to `false` so existing test suites keep their explicit-assert behavior.
- Auto-assert hooks into `MakesHttpRequests::createTestResponse()`. Responses you construct manually (outside `$this->get()`, `$this->post()`, etc.) are not touched.
- Idempotency is keyed on the `(spec, method, path)` tuple. Calling `assertResponseMatchesOpenApiSchema($response)` after auto-assert with the matching signature is a no-op. Calling it with a different `method`/`path` — or a different `#[OpenApiSpec]` — runs validation again.
- When auto-assert fails, the exception is thrown from inside `$this->get(...)`, so any chained assertion on the same line (`$this->get(...)->assertOk()`) will not run. This is usually what you want — the schema failure takes precedence over status-code checks.
- `auto_assert` accepts boolean-compatible values (`true`/`false`/`"1"`/`"0"`/`"true"`/`"false"`) so `'auto_assert' => env('OPENAPI_AUTO_ASSERT')` works. Unrecognized values fail the test loudly with a clear message, not silently.
- Streamed responses (`StreamedResponse`, binary downloads) cause `getContent()` to return `false`, which fails auto-assert with a clear message. If you use `auto_assert=true` on tests that exercise streams, scope the config change per-test or fall back to explicit manual asserts.

#### Opting out with `#[SkipOpenApi]`

Some tests intentionally return responses that violate the spec (error-injection tests, experimental endpoints with a not-yet-finalized contract, etc.). For these, use the `#[SkipOpenApi]` attribute to opt out of auto-assert without turning the feature off globally:

```php
use Studio\OpenApiContractTesting\SkipOpenApi;

class ExperimentalApiTest extends TestCase
{
    use ValidatesOpenApiSchema;

    #[Test]
    #[SkipOpenApi(reason: 'endpoint is behind an experimental flag')]
    public function test_experimental_endpoint(): void
    {
        $this->get('/v1/experimental');  // auto-assert is skipped
    }
}
```

The attribute can also be applied at the class level to skip every method in that class. A method-level `#[SkipOpenApi]` fully shadows the class-level one — only the method-level attribute (and its `reason`) is inspected.

Notes:

- `#[SkipOpenApi]` suppresses **auto-assert only**. Explicit calls to `assertResponseMatchesOpenApiSchema()` still run — the assertion is the user's direct intent.
- When auto-assert is skipped and no explicit assertion is made, no coverage is recorded for that request (the endpoint is treated as uncovered in the report). If you call `assertResponseMatchesOpenApiSchema()` explicitly on a skipped test, validation runs and coverage is recorded as usual.
- If a test is marked `#[SkipOpenApi]` and still calls `assertResponseMatchesOpenApiSchema()` explicitly, an advisory warning is written to `STDERR` and a user deprecation is raised to flag the contradictory intent. The assertion is not suppressed — fix the cause by removing either the attribute or the explicit call.
- The attribute is resolved via reflection on the direct class only; a class-level `#[SkipOpenApi]` on an abstract parent is **not** inherited by subclasses. Apply the attribute on each concrete test class (or per method) instead.

#### Per-request skip with `withoutValidation()`

When only a single request should skip validation — e.g., exercising a legacy endpoint during a staged migration — use the fluent `withoutValidation()` API instead of annotating the whole method:

```php
class PetApiTest extends TestCase
{
    use ValidatesOpenApiSchema;

    public function test_legacy_endpoint(): void
    {
        // Only this HTTP call skips validation.
        $this->withoutValidation()
            ->get('/v1/pets/legacy')
            ->assertOk();

        // The next call is validated as usual.
        $this->get('/v1/pets')->assertOk();
    }
}
```

Three scopes are available:

- `withoutValidation()` — skip both request and response validation
- `withoutResponseValidation()` — skip response validation only
- `withoutRequestValidation()` — skip request validation only (forward-looking hook; request validation itself is not yet implemented)

Notes:

- The flag applies to **exactly one HTTP call**. It is consumed on the next `$this->get()` / `$this->post()` / etc., then automatically resets — a second consecutive call validates normally.
- Scoped to auto-assert only, like `#[SkipOpenApi]`. An explicit `assertResponseMatchesOpenApiSchema()` call still runs regardless of the flag.
- No coverage is recorded for the skipped request.
- Each method returns `$this`, so both `$this->withoutValidation()->get(...)` and the step-by-step form (`$this->withoutValidation(); $this->get(...);`) work.

#### Per-request status-code skip with `skipResponseCode()`

`withoutValidation()` is all-or-nothing for a request. When you only need to suppress a specific status code — e.g., a flaky `404` while a fixture is being repaired — `skipResponseCode()` adds a one-off skip on top of the config-level set:

```php
class PetApiTest extends TestCase
{
    use ValidatesOpenApiSchema;

    public function test_endpoint_returning_a_documented_404(): void
    {
        // Only this call treats 404 as a skip; other calls keep the default behavior.
        $this->skipResponseCode(404)
            ->get('/v1/pets/missing')
            ->assertNotFound();
    }
}
```

Argument shapes:

- **`int`** — exact match, anchored. `skipResponseCode(500)` matches `"500"` only, never `"5000"` or `"50"`.
- **`string`** — regex pattern (anchored automatically). `skipResponseCode('4\d\d')` matches the entire `4xx` range.
- **`array`** — expanded one level. Mixed types are allowed: `skipResponseCode([404, '5\d\d'])`.
- **Variadic** — multiple positional arguments work too: `skipResponseCode(404, 500, '5\d\d')`.

Notes:

- **Merged** with `skip_response_codes` from config — per-request codes ADD to the config set rather than replacing it. With the default config, `skipResponseCode(404)` skips both `404` and every `5xx`.
- **One HTTP call**: same consumption model as `withoutValidation()`. The codes are consumed on the next auto-assert attempt and reset, so the next call falls back to the config-level set.
- **Auto-assert only**. Explicit `assertResponseMatchesOpenApiSchema()` calls ignore per-request codes — explicit calls are the user's direct intent.
- **Chainable**: returns `$this`. Multiple chained calls accumulate (`$this->skipResponseCode(404)->skipResponseCode(503)` registers both).
- `withoutRequestValidation()` currently has no observable effect because request validation has not landed yet — the method exists so tests can adopt the intended API surface today without a later rewrite.

## Coverage Report

After running tests, the PHPUnit extension prints a coverage report. The output format is controlled by the `console_output` parameter (or `OPENAPI_CONSOLE_OUTPUT` environment variable).

### `default` mode (default)

Shows covered endpoints individually and uncovered as a count:

```
OpenAPI Contract Test Coverage
==================================================

[front] 12/45 endpoints (26.7%)
--------------------------------------------------
Covered:
  ✓ GET /v1/pets
  ✓ POST /v1/pets
  ✓ GET /v1/pets/{petId}
  ✓ DELETE /v1/pets/{petId}
Uncovered: 41 endpoints
```

### `all` mode

Shows both covered and uncovered endpoints individually:

```
OpenAPI Contract Test Coverage
==================================================

[front] 12/45 endpoints (26.7%)
--------------------------------------------------
Covered:
  ✓ GET /v1/pets
  ✓ POST /v1/pets
  ✓ GET /v1/pets/{petId}
  ✓ DELETE /v1/pets/{petId}
Uncovered:
  ✗ PUT /v1/pets/{petId}
  ✗ GET /v1/owners
  ...
```

### `uncovered_only` mode

Shows uncovered endpoints individually and covered as a count — useful for large APIs where you want to focus on missing coverage:

```
OpenAPI Contract Test Coverage
==================================================

[front] 12/45 endpoints (26.7%)
--------------------------------------------------
Covered: 12 endpoints
Uncovered:
  ✗ PUT /v1/pets/{petId}
  ✗ GET /v1/owners
  ...
```

You can set the mode via `phpunit.xml`:

```xml
<parameter name="console_output" value="uncovered_only"/>
```

Or via environment variable (takes priority over `phpunit.xml`):

```bash
OPENAPI_CONSOLE_OUTPUT=uncovered_only vendor/bin/phpunit
```

## CI Integration

### GitHub Actions Step Summary

When running in GitHub Actions, the extension **automatically** detects the `GITHUB_STEP_SUMMARY` environment variable and appends a Markdown coverage report to the job summary. No configuration needed.

> **Note:** Both features are independent — when running in GitHub Actions with `output_file` configured, the Markdown report is written to both the file and the Step Summary.

### Markdown output file

Use the `output_file` parameter to write a Markdown report to a file. This is useful for posting coverage as a PR comment:

```xml
<extensions>
    <bootstrap class="Studio\OpenApiContractTesting\PHPUnit\OpenApiCoverageExtension">
        <parameter name="spec_base_path" value="openapi/bundled"/>
        <parameter name="specs" value="front,admin"/>
        <parameter name="output_file" value="coverage-report.md"/>
    </bootstrap>
</extensions>
```

You can also use the `OPENAPI_CONSOLE_OUTPUT` environment variable in CI to show uncovered endpoints in the job log:

```yaml
- name: Run tests (show uncovered endpoints)
  run: vendor/bin/phpunit
  env:
    OPENAPI_CONSOLE_OUTPUT: uncovered_only
```

Example GitHub Actions workflow step to post the report as a PR comment:

```yaml
- name: Run tests
  run: vendor/bin/phpunit

- name: Post coverage comment
  if: github.event_name == 'pull_request' && hashFiles('coverage-report.md') != ''
  uses: marocchino/sticky-pull-request-comment@v2
  with:
    path: coverage-report.md
```

## OpenAPI 3.0 vs 3.1

The package auto-detects the OAS version from the `openapi` field and handles schema conversion accordingly:

| Feature | 3.0 handling | 3.1 handling |
|---|---|---|
| `nullable: true` | Converted to type array `["string", "null"]` | Not applicable (uses type arrays natively) |
| `prefixItems` | N/A | Converted to `items` array (Draft 07 tuple) |
| `$dynamicRef` / `$dynamicAnchor` | N/A | Removed (not in Draft 07) |
| `examples` (array) | N/A | Removed (OAS extension) |
| `readOnly` / `writeOnly` | Removed (OAS-only in 3.0) | Preserved (valid in Draft 07) |

## API Reference

### `OpenApiResponseValidator`

Main validator class. Validates a response body against the spec.

The constructor accepts a `maxErrors` parameter (default: `20`) that limits how many validation errors the underlying JSON Schema validator collects. Use `0` for unlimited, `1` to stop at the first error.

The optional `responseContentType` parameter enables content negotiation: when provided, non-JSON content types (e.g., `text/html`) are checked for spec presence only, while JSON-compatible types proceed to full schema validation.

```php
$validator = new OpenApiResponseValidator(maxErrors: 20);
$result = $validator->validate(
    specName: 'front',
    method: 'GET',
    requestPath: '/api/v1/pets/123',
    statusCode: 200,
    responseBody: ['id' => 123, 'name' => 'Fido'],
    responseContentType: 'application/json',
);

$result->isValid();      // bool (true for both successes AND skipped results)
$result->isSkipped();    // bool (true when the status code matched skip_response_codes)
$result->errors();       // string[]
$result->errorMessage(); // string (joined errors)
$result->matchedPath();  // ?string (e.g., '/v1/pets/{petId}')
$result->skipReason();   // ?string (non-null when skipped)
```

### `OpenApiSpecLoader`

Manages spec loading and configuration.

```php
OpenApiSpecLoader::configure('/path/to/bundled/specs', ['/api']);
$spec = OpenApiSpecLoader::load('front');
OpenApiSpecLoader::reset(); // For testing
```

### `OpenApiCoverageTracker`

Tracks which endpoints have been validated.

```php
OpenApiCoverageTracker::record('front', 'GET', '/v1/pets');
$coverage = OpenApiCoverageTracker::computeCoverage('front');
// ['covered' => [...], 'uncovered' => [...], 'total' => 45, 'coveredCount' => 12]
```

## Development

```bash
composer install

# Run tests
vendor/bin/phpunit

# Static analysis
vendor/bin/phpstan analyse

# Code style
vendor/bin/php-cs-fixer fix
vendor/bin/php-cs-fixer fix --dry-run --diff  # Check only
```

## License

MIT License. See [LICENSE](LICENSE) for details.
