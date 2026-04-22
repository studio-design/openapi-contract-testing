<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use ReflectionClass;
use ReflectionMethod;

trait SkipOpenApiResolver
{
    private function shouldSkipOpenApi(): bool
    {
        return $this->findSkipOpenApiAttribute() !== null;
    }

    private function resolveSkipOpenApiReason(): string
    {
        $attr = $this->findSkipOpenApiAttribute();

        return $attr === null ? '' : $attr->reason;
    }

    private function findSkipOpenApiAttribute(): ?SkipOpenApi
    {
        // 1. Method-level #[SkipOpenApi] attribute
        $methodName = $this->name(); // @phpstan-ignore method.notFound
        $refMethod = new ReflectionMethod($this, $methodName);
        $methodAttrs = $refMethod->getAttributes(SkipOpenApi::class);
        if ($methodAttrs !== []) {
            return $methodAttrs[0]->newInstance();
        }

        // 2. Class-level #[SkipOpenApi] attribute
        $refClass = new ReflectionClass($this);
        $classAttrs = $refClass->getAttributes(SkipOpenApi::class);
        if ($classAttrs !== []) {
            return $classAttrs[0]->newInstance();
        }

        return null;
    }
}
