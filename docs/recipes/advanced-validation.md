# Fuzzing and drift-check recipes

Use these checks after the basic response assertion is stable:

- [Schema-driven fuzzing](../fuzzing.md) generates valid boundaries and targeted invalid cases with deterministic replay.
- [Enum drift detection](../enum-drift.md) compares OpenAPI enum values with PHP backed enums.
- [Strict-required detection](../strict-required.md) finds fields consistently returned by the implementation but optional in the contract.

Keep deterministic seeds in CI and print the replay token on failures. Enable drift checks as separate PHPUnit jobs so their ownership and failure messages stay clear.
