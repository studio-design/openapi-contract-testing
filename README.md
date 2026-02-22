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

To use a different spec for a specific test class, override `openApiSpec()`:

```php
class AdminGetUsersTest extends TestCase
{
    use ValidatesOpenApiSchema;

    protected function openApiSpec(): string
    {
        return 'admin';
    }

    // ...
}
```

#### Framework-agnostic

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

## Coverage Report

After running tests, the PHPUnit extension prints a coverage report:

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

$result->isValid();      // bool
$result->errors();       // string[]
$result->errorMessage(); // string (joined errors)
$result->matchedPath();  // ?string (e.g., '/v1/pets/{petId}')
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
