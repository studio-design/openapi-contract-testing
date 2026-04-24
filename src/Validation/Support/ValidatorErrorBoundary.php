<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Support;

use Exception;
use Studio\OpenApiContractTesting\OpenApiRequestValidator;
use Studio\OpenApiContractTesting\OpenApiResponseValidator;

use function sprintf;

/**
 * Per-sub-validator error boundary used by {@see OpenApiRequestValidator}
 * and {@see OpenApiResponseValidator}. Wraps a
 * single sub-validator call so that an `\Exception` thrown from its internals
 * (typically opis/json-schema's `SchemaException` family — `RuntimeException`
 * descendants like `UnresolvedReferenceException` / `ParseException`) is
 * converted into a standard error-string entry rather than aborting the whole
 * orchestrator and discarding errors already collected from sibling validators.
 *
 * `\Error` subclasses (TypeError, AssertionError, ...) are deliberately NOT
 * caught: those indicate programmer bugs and must keep bubbling so they are
 * not silently downgraded to a contract-validation error. This matches the
 * pattern used in `ValidatesOpenApiSchema::maybeInjectDummyBearer()`.
 */
final class ValidatorErrorBoundary
{
    /**
     * @param callable(): string[] $fn
     *
     * @return string[]
     */
    public static function safely(
        string $stage,
        string $specName,
        string $method,
        string $matchedPath,
        callable $fn,
    ): array {
        try {
            return $fn();
        } catch (Exception $e) {
            return [sprintf(
                "[%s] %s %s in '%s' spec: %s threw: %s",
                $stage,
                $method,
                $matchedPath,
                $specName,
                $e::class,
                $e->getMessage(),
            )];
        }
    }
}
