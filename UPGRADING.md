# Upgrade Guide

This file documents non-trivial upgrades between releases. Patches with
no behaviour change are not listed here — see `CHANGELOG.md` for the
full record.

## v0.x → v1.0.0

v1.0.0 is the API stability commitment. Anything not marked `@internal`
in v1.0.0 is covered by SemVer for the v1.x line.

### What's frozen by SemVer in v1.x

- Public class names and namespaces
- Public method signatures
- Public constants and their values
- Enum cases (additions are SemVer-minor; removals are SemVer-major)
- The `OpenApiValidationResult` shape
- The CLI surface of `bin/openapi-coverage-merge`
- The `OpenApiCoverageExtension` PHPUnit configuration parameters
- The Laravel `ValidatesOpenApiSchema` trait's public methods

### What's NOT frozen (will not break SemVer when changed)

- Anything marked `@internal`. This includes
  `OpenApiSpecLoader::clearCache/evict/reset`,
  `OpenApiCoverageTracker::reset/exportState/importState`,
  `ValidatesOpenApiSchema::resetValidatorCache`, and
  `OpenApiSchemaConverter::resetWarningStateForTesting`.
- The shape of paratest sidecar JSON (versioned via `STATE_FORMAT_VERSION`).
- Internal helper classes under `Internal/`, `Validation/Support/` (the
  classes themselves are not user-callable).
- Error messages from validators (we may improve them).
- The set of `format` keywords delegated to opis (we follow opis).

### What's NOT covered by SemVer at all

- OpenAPI features explicitly marked "not supported" or "presence-only"
  in README "Supported features and known limitations"
- Behaviour of bug-fix releases that close a documented silent-pass case
  (a test that passed only because of the silent pass may start failing —
  that's the bug fix doing its job, not a SemVer break)

### Migration notes by source version

There are **no breaking source-code changes** from any v0.16+ release to
v1.0.0. v1.0.0 is a stability promotion of v0.19.0 — every fix from the
v0.16 → v0.19 dogfood cycle is in v1.0.0 unchanged. The behavioural
changes listed below ship as part of those minors and may surface when
upgrading; review the section that matches your starting version and
forward.

If you are on v0.14.0 or older, apply the v0.14.0 → v0.15.0 namespace
migration first (see the table below), then read this section top-to-bottom.

#### Common to all v0.x → v1.0 upgrades — new `E_USER_WARNING`s

The library uses `trigger_error(..., E_USER_WARNING)` as its v1.0 official
silent-pass channel (see README → "Warning channel"). Tests configured
with PHPUnit's `failOnWarning="true"` (the default in this repo's
`phpunit.xml.dist`) will fail on first encounter. The categories that
ship between v0.16.0 and v1.0.0 are:

| Category prefix | Source | Warns on |
|---|---|---|
| `[security]` | `SecurityValidator` | `oauth2`, `openIdConnect`, `mutualTLS`, `http-basic`, `http-digest` schemes (silently passed before) |
| `[OpenAPI Schema]` | `OpenApiSchemaConverter` | `unevaluatedProperties` / `unevaluatedItems` (Draft 07 has no equivalent), `discriminator.mapping` (stripped — mapping does not steer validation), unknown / malformed `format` values |

Each warning is dedup'd per-process and prefixed with the category tag so
you can filter them mechanically. To suppress one category, install a
`set_error_handler` that matches the prefix; do NOT blanket-suppress
`E_USER_WARNING`. To stay green without filtering, set
`failOnWarning="false"` in your project's `phpunit.xml.dist`.

#### From v0.15.0 → v1.0.0

- No source-code changes required.
- All warnings in the table above apply; previously these were silent passes.
- v0.15.0 already required updating `use Studio\OpenApiContractTesting\X`
  imports per the v0.14.0 → v0.15.0 migration table — that is unchanged.

#### From v0.16.0 → v1.0.0

- No source-code changes required.
- **`discriminator.mapping`** now warns on first encounter (#147). Specs
  with non-empty `mapping` previously silently dropped the keyword;
  validation behaviour is unchanged (the underlying `oneOf` / `anyOf` is
  still validated as a union).
- **Unknown / malformed `format` keywords** now warn (#151). A typo like
  `format: emial` previously silently passed every string; the converter
  now emits a one-shot warning per unknown format value.

#### From v0.17.0 → v1.0.0

- No source-code changes required.
- **Multi-JSON-per-status schema selection** behaves differently (#152).
  When a spec declares multiple JSON keys for the same status (e.g.
  `application/json` AND `application/problem+json`) and the response
  carries a JSON-flavoured `Content-Type`, the validator now prefers the
  spec key that exactly matches the actual `Content-Type` before falling
  back to the first JSON key. Pre-v0.17 behaviour was first-JSON-wins —
  problem-details bodies served as `application/problem+json` were judged
  against the success-shape `application/json` schema. Single-JSON specs
  and vendor `+json` suffixes the spec doesn't enumerate are unaffected.

#### From v0.18.0 → v1.0.0

- No source-code changes required.
- **`additionalProperties: false` cascade dedup** strips the pseudo-error
  that named declared properties as not-allowed when any sub-property
  failed (#159). A 1-error failure that previously inflated to 2 errors
  collapses back to 1. If a test was asserting the exact error count or
  the cascade message, update the assertion.

#### From v0.19.0 → v1.0.0

- No source-code changes required.
- **Cascade dedup now walks across array boundaries** (#161). Cascades
  through `{ data: [Item] }`-shaped envelopes — including the shape
  `OpenApiSchemaConverter` lowers OAS 3.1 `prefixItems` to — collapse the
  same way as the root-level dedup did in v0.18.0. Same assertion-update
  caveat as v0.18.0.

## v0.14.0 → v0.15.0

The "v1.0 prep" release. Twenty-two classes moved into focused
sub-namespaces. No compat shim — pre-1.0 breaking changes are the
contract for this release line.

See the v0.15.0 entry in `CHANGELOG.md` for the full migration table.
The mechanical fix is updating `use Studio\OpenApiContractTesting\X`
imports per the table.
