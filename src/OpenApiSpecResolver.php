<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use ReflectionClass;
use ReflectionMethod;

trait OpenApiSpecResolver
{
    protected function openApiSpecFallback(): string
    {
        return '';
    }

    private function resolveOpenApiSpec(): string
    {
        // 1. Method-level #[OpenApiSpec] attribute
        $methodName = $this->name(); // @phpstan-ignore method.notFound
        $refMethod = new ReflectionMethod($this, $methodName);
        $methodAttrs = $refMethod->getAttributes(OpenApiSpec::class);
        if ($methodAttrs !== []) {
            return $methodAttrs[0]->newInstance()->name;
        }

        // 2. Class-level #[OpenApiSpec] attribute
        $refClass = new ReflectionClass($this);
        $classAttrs = $refClass->getAttributes(OpenApiSpec::class);
        if ($classAttrs !== []) {
            return $classAttrs[0]->newInstance()->name;
        }

        // 3. Subclass hook (e.g. openApiSpec() in Laravel trait)
        return $this->openApiSpecFallback();
    }
}
