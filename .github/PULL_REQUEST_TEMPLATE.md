<!--
Title format: Conventional Commits — feat(scope): …, fix(scope): …, docs: …
Subject must start lowercase. A CI check enforces this.
-->

## Summary

<!-- What changes, in 1–3 sentences. -->

## Why

<!-- The problem you're solving. Link the issue if any: "Fixes #123" -->

## Verification

<!-- How did you test this? Failing-test-then-fix is preferred for bugs. -->

- [ ] `composer test` passes
- [ ] `composer stan` passes
- [ ] `composer cs-check` passes

<!--
CHANGELOG.md is managed by release-please and must NOT be edited in
feature / fix PRs. The release PR updates it automatically based on
Conventional Commits. See CONTRIBUTING.md → "Releases".
-->


## Notes for reviewers

<!-- Anything non-obvious. Trade-offs you considered. Risks. -->
