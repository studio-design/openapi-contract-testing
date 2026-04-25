# Changelog

All notable changes to this project will be documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

This project is **pre-1.0**, so breaking changes may land in any minor release
until 1.0.0 ships. Each entry below tags whether it is breaking.

## Unreleased

### Breaking

- **Coverage granularity expanded** to `(method, path, statusCode, contentType)`
  ([#111](https://github.com/studio-design/openapi-contract-testing/issues/111)).
  - `OpenApiCoverageTracker::record()` is **removed**. Use the new
    `recordRequest(spec, method, path)` and
    `recordResponse(spec, method, path, statusKey, contentTypeKey, schemaValidated, skipReason?)`.
    Library-internal call sites (the Laravel trait) are updated automatically;
    direct callers must migrate.
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
  declared on the same endpoint via the
  `OpenApiCoverageTracker::statusKeyMatches()` helper.
