<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Exception;

use RuntimeException;
use Studio\OpenApiContractTesting\Spec\OpenApiSchemaConverter;
use Studio\OpenApiContractTesting\Validation\Support\MalformedSpecNode;
use Throwable;

/**
 * Thrown by {@see OpenApiSchemaConverter}
 * when a `discriminator` block is structurally malformed while enforcement is
 * active (Issue #262) — a missing / non-string `propertyName`, a non-array
 * `mapping`, a non-string mapping value, or a mapping pointer that does not
 * resolve to a schema object in the root spec.
 *
 * Extends {@see RuntimeException} so the body validators' existing
 * `validateBody()` boundary catches it and surfaces it as one loud, clean
 * validation failure (`"... threw: Malformed 'discriminator' ..."`), exactly
 * like the {@see MalformedSpecNode}
 * structural guards — rather than letting a malformed spec silently bypass the
 * enforcement it opted into.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class MalformedDiscriminatorException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
