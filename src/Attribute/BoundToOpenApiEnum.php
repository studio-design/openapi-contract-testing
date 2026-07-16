<?php

declare(strict_types=1);

namespace Studio\Gesso\Attribute;

use Attribute;
use Studio\Gesso\Schema\EnumDriftAsserter;

/**
 * Bind a backed PHP enum to its OpenAPI `enum` definition file. Pure
 * marker — carries no runtime behavior outside {@see EnumDriftAsserter},
 * which reads it via reflection and resolves `$specPath` relative to the
 * configured enum root (`OpenApiSpecLoader::getEnumBasePath()`, falling
 * back to `OpenApiSpecLoader::getBasePath()`).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class BoundToOpenApiEnum
{
    public function __construct(
        public readonly string $specPath,
    ) {}
}
