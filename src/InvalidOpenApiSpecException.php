<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use RuntimeException;

/**
 * Thrown when an OpenAPI spec is syntactically parseable but semantically
 * broken in a way that makes contract validation impossible — currently
 * limited to `$ref` resolution failures (external / circular / unresolvable
 * / malformed / bare-fragment / non-string).
 *
 * Separate from generic `RuntimeException` so the PHPUnit extension can
 * hard-fail the run on broken specs while continuing to `warn` on lesser
 * issues such as a missing spec file.
 */
final class InvalidOpenApiSpecException extends RuntimeException {}
