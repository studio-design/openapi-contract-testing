# Native JSON Schema 2020-12 benchmark

Measured on 2026-07-10 with PHP 8.3.31 and opis/json-schema 2.6.0. Each result
is the median of five rounds with 10,000 iterations per round.

| Pipeline | Conversion (ms) | Validation (ms) | Validation ops/s |
|---|---:|---:|---:|
| OpenAPI 3.0 → Draft 07 compatibility | 105.74 | 120.55 | 82,955 |
| OpenAPI 3.1 → native 2020-12 | 64.89 | 122.43 | 81,679 |

For this representative object schema, native validation was 1.6% slower while
conversion was 38.6% faster because 2020-12 keywords no longer need lowering.
The difference is small relative to HTTP/framework test execution, but the
benchmark is retained so future Opis or converter changes can be compared.

Reproduce locally:

```bash
php benchmarks/schema-dialect-pipeline.php 10000
```

The two schemas model equivalent required fields, closed objects, and a
credit-card dependency using Draft 07 `if`/`then` versus 2020-12
`dependentRequired`. This is a focused pipeline microbenchmark, not an HTTP
adapter benchmark.
