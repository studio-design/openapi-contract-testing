# Contributing

Thanks for considering a contribution. This is a small library with a
single maintainer; the workflow below is optimised for quick iteration.

## Quick start

```bash
git clone https://github.com/studio-design/openapi-contract-testing
cd openapi-contract-testing
composer install
composer ci   # runs cs-check + stan + test
```

PHP 8.2 or newer is required. The CI matrix runs PHP 8.2/8.3/8.4 with
PHPUnit 11/12/13.

## Local checks

| Command                | What it does                                  |
| ---------------------- | --------------------------------------------- |
| `composer test`        | Run the full test suite (PHPUnit)             |
| `composer stan`        | PHPStan level 6 over `src/` and `tests/`      |
| `composer cs-check`    | PHP-CS-Fixer dry-run                          |
| `composer cs`          | PHP-CS-Fixer apply (rewrites files)           |
| `composer ci`          | All of the above, in CI order                 |

## Workflow

1. Open an issue describing the problem before starting non-trivial work.
   For bug fixes that come with a failing test, jumping straight to a PR
   is fine.
2. Fork, branch from `main`, name the branch with the convention
   `feat/<topic>`, `fix/<topic>`, `docs/<topic>`, etc.
3. Write tests first when the change is observable behaviour. The test
   suite uses snake_case method names and PHPUnit `#[Test]` attributes.
4. Run `composer ci` locally before pushing.
5. Open a PR. The title must follow Conventional Commits
   (`feat(scope): …`, `fix(scope): …`, etc.) — a CI check enforces this.
6. The PR template asks for the failing scenario and how the change is
   verified. Skipping it slows review.

## Commits

History is squash-merged; the squash uses the PR title. So:
- The PR title becomes the canonical commit message
- Conventional Commits convention is enforced on PR titles
- Commit titles inside the PR are free-form; aim for readable history but
  don't worry about formatting since they get squashed

## Code style

PER-CS2.0 + `@PHP8x2Migration` via PHP-CS-Fixer (config in
`.php-cs-fixer.dist.php`). Highlights:

- `declare(strict_types=1);` in every PHP file
- Strict comparisons (`===`, `!==`) only
- Explicit `use function` / `use const` imports — no global namespace
  fallback in production code
- Class element order: traits → constants → static properties →
  properties → constructor → public static methods → public → protected
  → private
- Tests use snake_case method names

If `composer cs-check` fails, run `composer cs` and commit the result.

## Adding new public API

1. Mark the class `final` unless you have a concrete extension story
2. Avoid adding public properties — favour readonly DTOs or accessors
3. Methods that are only public for the PHPUnit extension or paratest
   sidecar protocol must carry an `@internal` annotation in the docblock
   with a one-line explanation of why they cannot be `private`
4. Document the behaviour in README before merging

## Releases

Releases are automated by [release-please](https://github.com/googleapis/release-please).
Maintainers do **not** edit `CHANGELOG.md` or push tags by hand.

How it works:

1. Every push to `main` runs the `release-please` workflow.
2. The bot scans the Conventional Commits since the last release tag and
   either opens or updates a "release PR" titled `chore(main): release X.Y.Z`.
   The PR diff bumps `CHANGELOG.md` and `.release-please-manifest.json`.
3. The proposed version is derived from the commit prefixes:
   - `fix:` → patch bump (`X.Y.Z` → `X.Y.(Z+1)`)
   - `feat:` → minor bump (`X.Y.Z` → `X.(Y+1).0`)
   - `feat!:` or `BREAKING CHANGE:` footer → major bump (`X.Y.Z` → `(X+1).0.0`)
   - `docs:` / `chore:` / `refactor:` / `test:` / etc. → no bump on their own
4. When a maintainer merges the release PR, the bot creates the git tag and
   publishes the GitHub Release with notes generated from the changelog
   entry. Packagist syncs automatically from the tag webhook.

### Discipline

- **Never push a `v*` tag manually.** The release-please manifest tracks
  the last released SHA; a hand-tag desyncs it and the next release PR
  proposes a wrong version. If you need a hotfix release, land the fix
  via a regular `fix:` PR and let the bot's release PR ship it.
- **Never edit `CHANGELOG.md` manually** in feature/fix PRs. The release
  PR is the only place CHANGELOG should change. If a release entry is
  worded poorly, edit the release PR before merging it.
- **Squash-merge release PRs as-is.** Do not edit the squash subject —
  the bot uses it to detect that the merged commit is a release.

### Forcing a specific bump

If a `fix:`-only batch should ship as a minor release (e.g., the fix is
behaviourally significant), use a `Release-As: X.Y.Z` footer in any commit
on `main`:

```
fix(validator): rewire something important

Release-As: 1.1.0
```

The release PR will adopt that version on its next refresh.

## Reporting bugs

Use the bug-report issue template. The fastest way to a fix is a
self-contained reproduction, ideally as a failing test against the
current `main`.

## Security issues

See `SECURITY.md`. **Do not file security reports as public issues.**
