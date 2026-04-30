# Changelog

All notable changes to this project will be documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

This project is **pre-1.0**, so breaking changes may land in any minor release
until 1.0.0 ships. Each entry below tags whether it is breaking.

## Unreleased

### Added

- **#136 — Schema-driven request fuzzing (`ExploresOpenApiEndpoint` trait)**.
  Generates N happy-path request inputs for a single (method, path) operation
  directly from the OpenAPI spec — the first PHP library to ship Schemathesis
  / property-based contract testing as a built-in primitive. Spec-name
  resolution mirrors `ValidatesOpenApiSchema` (method/class `#[OpenApiSpec]`
  attribute → `openApiSpec()` override → `default_spec` config). Each generated
  case is wrapped in an `ExploredCase` DTO (body / query / headers / pathParams)
  and the returned `ExplorationCases` collection exposes both `foreach` and a
  fluent `each(callable)` helper:
  ```php
  $this->exploreEndpoint('POST', '/v1/pets', cases: 50)
      ->each(fn ($input) => $this->postJson('/api/v1/pets', $input->body)
          ->assertSuccessful());
  ```
  The HTTP path through Laravel keeps using the existing `ValidatesOpenApiSchema`
  auto-assert hook, so response validation and coverage tracking are unchanged.
  When `fakerphp/faker` is installed (already transitive via
  `orchestra/testbench`), generation produces realistic strings (email, uuid,
  URLs) and a `seed:` argument locks output for deterministic CI; without
  faker, the generator falls back to deterministic primitives that still pass
  the schema. Out-of-scope for this slice (tracked separately): boundary
  values, negative-case generation, `oneOf`/`anyOf`/`allOf` composition, regex
  `pattern`, and full-spec auto-exploration.

- **#135 — `min_coverage` threshold gate for CI**. New PHPUnit extension
  parameters `min_endpoint_coverage` / `min_response_coverage` (percent,
  optional) and `min_coverage_strict` (default `false` → warn-only, set to
  `true` to exit non-zero on a miss) make contract coverage gateable the
  same way PHPUnit's own `--coverage-threshold` works. The gate aggregates
  over every spec listed in `specs=` (raw covered/total counts are summed
  across specs, then a single percent is compared). The merge CLI gains
  matching `--min-endpoint-coverage` / `--min-response-coverage` /
  `--min-coverage-strict` flags so paratest workflows can gate too. Both
  paths emit a `[OpenAPI Coverage] FAIL: …` line to stderr on a strict
  miss (or `WARN:` when warn-only).
  - Strict mode **fails fast on an unevaluable gate**, never silently passes:
    a non-numeric / out-of-range threshold throws
    `InvalidThresholdConfigurationException` (extension) or exits 2 (merge
    CLI), and an empty test run with a configured threshold exits 1 with
    `no contract test coverage was recorded`. Warn-only mode keeps the
    historical exit codes and only logs.

### Fixed

- **#131 — Clean stack trace for contract validation failures**. PHPUnit's
  failure block no longer surfaces this library's internal frames or
  Laravel's `MakesHttpRequests` / `TestResponse` testing-concern frames
  before the user's test line. The trait intercepts the
  `AssertionFailedError` raised at its own failure sites and re-throws
  with a trimmed trace. Behavior change is trace-only — exception type,
  message, and the user-visible test line are unchanged. No user-side
  configuration is required.

## v0.14.0 — 2026-04-28

Parallel-runner support unblocks `pest --parallel` / paratest in
downstream CI. Non-breaking minor release: sequential PHPUnit behaviour
is unchanged; the new behaviour is gated on paratest's `TEST_TOKEN`
environment variable.

### Added

- **#129 — paratest / Pest `--parallel` support**. Coverage state is
  per-process, so under paratest each worker previously produced a
  partial report that either overwrote (`output_file`) or stacked
  (`GITHUB_STEP_SUMMARY`) the others. The extension now detects
  paratest workers via `TEST_TOKEN` and writes per-worker JSON sidecars
  instead of rendering. A new `vendor/bin/openapi-coverage-merge` CLI
  combines the sidecars into a single report after the parallel run
  finishes — same workflow shape as `phpunit/php-code-coverage` +
  `phpcov merge`.
- New optional `sidecar_dir` parameter on `OpenApiCoverageExtension`
  (default `sys_get_temp_dir()/openapi-coverage-sidecars`). Workers
  drop sidecars here; the merge CLI reads from the same path.
- New public APIs: `OpenApiCoverageTracker::exportState()` /
  `importState()` expose the JSON-safe state and union-merge primitives
  for callers building custom aggregators. `STATE_FORMAT_VERSION = 1`
  is stamped on every payload.
- New `vendor/bin/openapi-coverage-merge` CLI. Flags:
  `--spec-base-path`, `--specs`, `--strip-prefixes`, `--sidecar-dir`,
  `--output-file`, `--github-step-summary`, `--console-output`,
  `--no-cleanup`, `--help`.
- **Worker write failures now fail the merge loudly.** When a worker
  cannot persist its sidecar it drops a `failed-<token>.json` marker
  in the sidecar dir; the merge CLI exits non-zero (`FATAL`) when any
  markers are present, since a missing worker would silently
  under-count coverage.

### Documentation

- README adds a "Parallel test runners" section covering the workflow,
  sidecar directory defaults, the full CLI flag table, and notes on
  failure-marker handling, stale sidecars across runs, and the merge
  CLI's inability to set `allowRemoteRefs` (consumers using HTTP `$ref`
  must pre-bundle or wrap the merge step).

### Migration

- Sequential PHPUnit / Pest runs need no changes — the new code paths
  are gated on `TEST_TOKEN` (set only by paratest).
- Parallel runs require an extra step: after `pest --parallel` /
  `paratest`, run `vendor/bin/openapi-coverage-merge --spec-base-path=…
  --specs=…` to combine the sidecars into a single coverage report.

Install via `composer require --dev studio-design/openapi-contract-testing:^0.14`.

## v0.13.0 — 2026-04-25

Spec compliance + critical bug fix surfaced by the v0.12.0 dogfood
session against a real Laravel 12 project.

### Fixed

- **#124 — Empty `{}` body validates against `type: object`** (was the
  v1.0 blocker from dogfood). PHP's `json_decode('{}', true) === []`
  collided with the body validator preserving `[]` as a JSON array, so
  the natural Laravel `$response->json()` path failed against a spec
  declaring `type: object`. The body validator now coerces `[]` to
  `stdClass` when the schema's top-level type explicitly accepts an
  object (`type: object` or `type: ["object", ...]` in OAS 3.1).
  Composition keywords (`oneOf` / `anyOf` / `allOf`) intentionally do
  NOT trigger coercion — type-mismatch errors still surface for
  `type: array` schemas where the empty-array body is genuinely wrong.

### Added

- **#125 — `default` response key support**. The validator now
  consults `responses.default` when the literal status code isn't
  declared. Validation still runs the schema; the matched key is
  reported as `"default"` in `OpenApiValidationResult::matchedStatusCode()`
  and in coverage rows.
- **#126 — `1XX`/`2XX`/`3XX`/`4XX`/`5XX` range key support** (case-insensitive
  per spec; both `5XX` and `5xx` accepted). The validator now matches
  range keys when no exact status key is declared. The matched spec
  key (preserving the spec author's casing) flows through to coverage.
- Lookup priority is **exact > range > default** (the conventional
  resolution shared by major OpenAPI tooling — the spec describes the
  three forms but does not normatively rank them). Specs that declare
  both `503` and `5XX` resolve `503` to the explicit entry; `599` falls
  through to `5XX`; `418` falls through to `default` if declared.
- `tests/fixtures/specs/spec-fallback.json` exercises every priority
  branch in the new lookup.

### Changed

- **`OpenApiValidationResult::matchedStatusCode()` semantics shift**:
  pre-v0.13, this always returned the literal HTTP status string (e.g.
  `"503"`). With the new fallback, it returns the **spec key** the
  validator actually matched — so a spec declaring only `5XX` now
  reports `matchedStatusCode() === "5XX"` for a 503 response. Callers
  building `503 → row` maps from the result will see the key change
  for any status that resolves via fallback. Skipped responses still
  report the literal status (skip happens before key resolution).

### Documentation

- **#123** — README comparison table now correctly shows `✅` for
  response header validation (was `❌` despite shipping in v0.12.0).
- **#127** — README Installation section calls out the
  `symfony/yaml` requirement for YAML specs as a dedicated callout
  block, not buried in the Requirements list.

## v0.12.0 — 2026-04-25

This release closes Sprint A — every issue scheduled before v1.0 except the
monorepo split (#114). Big-ticket items: response header validation, external
`$ref` resolution (local files + opt-in HTTP(S)), and a fully overhauled
coverage tracker that records at `(status, content-type)` granularity. See
each section below for migration notes.

### Sprint A highlights

- **#110 — Response header validation**: `OpenApiResponseValidator::validate()`
  now accepts an optional `?array $responseHeaders` and validates them against
  the spec's `headers:` block on the matched response. Errors use the
  `[response-header.<Name>]` prefix. The Laravel trait wires this in
  automatically by passing `$response->headers->all()`.
- **#108 — External `$ref` resolution**: relative file refs
  (`./schemas/pet.yaml#/Pet`) now resolve transparently at spec load time;
  cycles, broken paths, and missing files surface as structured errors rather
  than crashes. Opt-in HTTP(S) ref resolution is available by passing a
  PSR-18 `ClientInterface` + PSR-17 `RequestFactoryInterface` to
  `OpenApiSpecLoader::configure()` with `allowRemoteRefs: true` —
  Guzzle / Symfony HttpClient both work. See `composer.json` `suggest`.
- **#112 — README competitor comparison**: full feature matrix vs Spectator,
  league/openapi-psr7-validator, hkulekci/...

### Breaking — coverage granularity (#111)

- **Coverage granularity expanded** to `(method, path, statusCode, contentType)`
  ([#111](https://github.com/studio-design/openapi-contract-testing/issues/111)).
  - `OpenApiCoverageTracker::record()` is **removed**. Use the new
    `recordRequest(spec, method, path)` and
    `recordResponse(spec, method, path, statusKey, contentTypeKey, schemaValidated, skipReason?)`.
    Library-internal call sites (the Laravel trait) are updated automatically;
    direct callers must migrate. `$contentTypeKey` is `?string`: pass `null`
    when no content-type lookup applies (e.g. the response was skipped before
    content negotiation, or it is a 204-style entry) — null is internally
    stored under the `*` sentinel and reconciled against spec declarations
    that have no `content` block.
  - `OpenApiCoverageTracker::__construct` is now `private`. The class was
    static-only in practice but you could previously call `new` on it; that
    now raises `Error`. Use the static API directly.
  - `OpenApiCoverageTracker::computeCoverage()` returns a new shape with
    per-endpoint sub-rows (one per declared `(status, content-type)` pair),
    response-level totals, an `EndpointSummary['state']` field
    (`all-covered` | `partial` | `uncovered` | `request-only`), and an
    `unexpectedObservations` list. Old keys (`covered`, `uncovered`, `total`,
    `coveredCount`, `skippedOnly`, `skippedOnlyCount`) are gone.
  - `OpenApiCoverageTracker::getCovered()` is retained as a diagnostic shim
    returning `array<spec, array<"METHOD path", true>>`. Prefer
    `hasAnyCoverage(spec): bool` for presence checks and `computeCoverage()`
    for full results.
  - `MarkdownCoverageRenderer` and `ConsoleCoverageRenderer` produce
    visibly different output (per-endpoint sub-rows, partial/skipped
    markers). Consumers that scrape these outputs may need updates.
  - `OpenApiResponseValidator::validate()` now returns
    `OpenApiValidationResult::skipped()` (was: silent `success()`) when the
    spec declares only non-JSON content types and the caller did not supply
    a `responseContentType` — there is no schema engine to validate against,
    so coverage records the response as `skipped` instead of crediting it
    as validated. Callers that pass the actual response Content-Type
    continue to be marked `validated` whenever the type is in the spec.
  - Coverage rates are now reported at **two granularities** — endpoint
    (`endpointFullyCovered / endpointTotal`) and response definition
    (`responseCovered / responseTotal`).

### Added

- `OpenApiValidationResult::matchedStatusCode()` and `matchedContentType()`
  expose which spec response key and media-type key the validator selected,
  so coverage tracking can record per-pair granularity. For skipped
  responses, `matchedStatusCode()` is the **literal HTTP status string**
  (e.g. `"503"`) and is reconciled to spec range keys (`5XX` / `5xx` /
  `default`) at compute time.
- `OpenApiCoverageTracker::ANY_CONTENT_TYPE` (`"*"`) sentinel for responses
  recorded before content-type lookup (skipped, 204, non-JSON-only).
- `Validation/Response/ResponseBodyValidationResult` DTO carries the body
  validator's errors plus the matched spec content-type key. Replaces the
  bare `string[]` return.
- New fixture `tests/fixtures/specs/range-keys.json` exercising spec-side
  `5XX` and `default` response keys for reconciliation tests.
- `CoverageReportSubscriber` extracted from the inline anonymous subscriber
  in `OpenApiCoverageExtension` so PHPStan can resolve the imported
  `CoverageResult` shape end-to-end.

### Changed

- `Validation/Response/ResponseBodyValidator::validate()` returns
  `ResponseBodyValidationResult` instead of `string[]`. Internal class —
  external callers were not expected, but if you instantiated it directly,
  unwrap `->errors` from the new return value.
- `Validation/Support/ContentTypeMatcher::findContentTypeKey()` is added
  to surface the spec's literal media-type key (with original casing)
  matching a normalized content-type. Used by the body validator and
  coverage tracker so the spec author's casing is preserved in reports.

### Notes

- Spec-undefined statuses observed at runtime (e.g. real `503` against a
  spec that declares neither `503` nor `5XX`) now appear in
  `EndpointSummary['unexpectedObservations']` rather than silently inflating
  endpoint coverage. Behavior change worth flagging for users that relied
  on the old loose counting.
- Skip-by-status-code remains opt-in via `skip_response_codes`; matched
  literal statuses (e.g. `"503"`) reconcile against any spec range key
  (`5XX` / `5xx` / `default`) declared on the same endpoint at compute
  time, so a `5\d\d` skip on an endpoint declaring `5XX: application/json`
  surfaces as `skipped` rather than `uncovered`.
- A spec that declares both `default` and `5XX` for the same content-type
  on the same endpoint is treated as **two distinct response definitions**
  in the totals — a single `503` recording credits both. Spec authors
  rarely write both; if they do, response-level totals reflect that.
- `OpenApiCoverageTracker::computeCoverage()` emits `E_USER_WARNING`
  (visible to PHPUnit) when the spec contains structurally invalid
  branches inside `paths` (e.g. `responses: 200` as a scalar). Coverage
  proceeds with the malformed entries omitted; without the warning the
  user would silently see a smaller `responseTotal` than reality.

### Other changes since v0.11.0

40 PRs landed in this window. Highlights not already covered above:

- Auto-validate-request hook (#69 — Laravel trait validates the request
  body, query, headers, cookies, and security against the spec on every
  HTTP test call when `auto_validate_request: true`)
- Auto-inject-dummy-bearer for `actingAs()` tests (#75)
- `withoutValidation()` / `withoutResponseValidation()` /
  `withoutRequestValidation()` per-test scoped opt-outs
- `skipResponseCode()` per-test fluent API on top of the config-level
  `skip_response_codes` (#76)
- `#[SkipOpenApi]` attribute for class- and method-level opt-outs (#55)
- `#[OpenApiSpec]` attribute for class- and method-level spec selection
- `OpenApiValidationOutcome` enum — exhaustive `match` over
  `Success` / `Failure` / `Skipped` instead of the two prior bool predicates
- Per-sub-validator error boundary (#92) — opis `RuntimeException`
  thrown from a body or header validator no longer aborts the whole
  orchestrator; surfaces as a structured `[response-body]` /
  `[response-header]` error string with the original `getPrevious()`
  chain preserved
- YAML spec loading via `symfony/yaml` (#80)
- Internal `$ref` resolution (#77)
- readOnly / writeOnly enforcement on response / request bodies (#52)
- Vendor-suffix JSON content types (`application/problem+json`,
  `application/vnd.api+json`, ...) treated as JSON-compatible
- Strip-prefixes (`/api`) for path matching
- `console_output` modes: `default` / `all` / `uncovered_only`
  (overridable via `OPENAPI_CONSOLE_OUTPUT` env var)

For the complete commit list:
`git log v0.11.0..v0.12.0 --oneline`.
