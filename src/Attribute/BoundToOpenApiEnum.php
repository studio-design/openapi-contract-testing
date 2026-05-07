<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Attribute;

use Attribute;
use Studio\OpenApiContractTesting\Schema\EnumDriftAsserter;

/**
 * Bind a backed PHP enum to its OpenAPI `enum` definition file. Pure
 * marker — carries no runtime behavior outside {@see EnumDriftAsserter},
 * which reads it via reflection and resolves `$specPath` relative to the
 * configured spec root (`OpenApiSpecLoader::getBasePath()`).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class BoundToOpenApiEnum
{
    public function __construct(
        public readonly string $specPath,
    ) {}
}
