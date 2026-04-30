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
- [ ] CHANGELOG.md updated under `## Unreleased` (skip for refactor/docs-only PRs)

## Notes for reviewers

<!-- Anything non-obvious. Trade-offs you considered. Risks. -->
