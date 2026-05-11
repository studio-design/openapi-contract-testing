# API Reference

- [`OpenApiResponseValidator`](#openapiresponsevalidator)
- [`OpenApiSpecLoader`](#openapispecloader)
- [`OpenApiCoverageTracker`](#openapicoveragetracker)

## `OpenApiResponseValidator`

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

$result->outcome();      // OpenApiValidationOutcome (Success | Failure | Skipped)
$result->isValid();      // bool (true for both successes AND skipped results)
$result->isSkipped();    // bool (true when the status code matched skip_response_codes)
$result->errors();       // string[]
$result->errorMessage(); // string (joined errors)
$result->matchedPath();  // ?string (e.g., '/v1/pets/{petId}')
$result->skipReason();   // ?string (non-null when skipped)
```

Prefer `outcome()` when you need to distinguish all three states explicitly — PHPStan enforces `match` exhaustiveness, so adding a future outcome cannot silently slip past a caller:

```php
use PHPUnit\Framework\AssertionFailedError;
use Studio\OpenApiContractTesting\OpenApiValidationOutcome;

match ($result->outcome()) {
    OpenApiValidationOutcome::Success => null, // schema matched
    OpenApiValidationOutcome::Failure => throw new AssertionFailedError($result->errorMessage()),
    OpenApiValidationOutcome::Skipped => logger()->info('skipped', ['reason' => $result->skipReason()]),
};
```

## `OpenApiSpecLoader`

Manages spec loading and configuration.

```php
OpenApiSpecLoader::configure('/path/to/bundled/specs', ['/api']);
$spec = OpenApiSpecLoader::load('front');
OpenApiSpecLoader::reset(); // For testing
```

## `OpenApiCoverageTracker`

Tracks which endpoints have been exercised, at `(method, path, statusCode, contentType)` granularity. The Laravel trait records via the tracker automatically; framework-agnostic adapters call it directly.

```php
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;

// Request-side: an endpoint was reached without a response assertion
OpenApiCoverageTracker::recordRequest('front', 'GET', '/v1/pets');

// Response-side: full granularity (status + content-type spec keys)
OpenApiCoverageTracker::recordResponse(
    specName: 'front',
    method: 'GET',
    path: '/v1/pets',
    statusKey: '200',                  // spec key, or literal status when skipped
    contentTypeKey: 'application/json',// spec key (case preserved); null → "*"
    schemaValidated: true,             // false → state=skipped
    skipReason: null,
);

$coverage = OpenApiCoverageTracker::computeCoverage('front');
// [
//   'endpoints' => [...per-endpoint EndpointSummary, includes per-response sub-rows...],
//   'endpointTotal' => 45,
//   'endpointFullyCovered' => 12,
//   'endpointPartial' => 8,
//   'endpointUncovered' => 25,
//   'responseTotal' => 120,
//   'responseCovered' => 38,
//   'responseSkipped' => 4,
//   'responseUncovered' => 78,
//   ...
// ]
```

`hasAnyCoverage(spec): bool` is a fast presence check. `getCovered()` is retained as a diagnostic shim returning `array<spec, array<"METHOD path", true>>`. See [CHANGELOG.md](../CHANGELOG.md) for the migration from the pre-#111 endpoint-level shape.
