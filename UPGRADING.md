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

### Migration notes from v0.15.0

- No breaking source-code changes from v0.15.0.
- New `E_USER_WARNING` may fire for specs using `patternProperties`,
  `unevaluatedProperties`, `unevaluatedItems`, `contentMediaType`, or
  `contentEncoding`. Tests configured with PHPUnit's `failOnWarning="true"`
  (the default in this repo's `phpunit.xml.dist`) will fail loudly if
  these are encountered. To suppress: rewrite the spec using Draft 07
  equivalents, or set `failOnWarning="false"` in your project's
  `phpunit.xml.dist`.

## v0.14.0 → v0.15.0

The "v1.0 prep" release. Twenty-two classes moved into focused
sub-namespaces. No compat shim — pre-1.0 breaking changes are the
contract for this release line.

See the v0.15.0 entry in `CHANGELOG.md` for the full migration table.
The mechanical fix is updating `use Studio\OpenApiContractTesting\X`
imports per the table.
