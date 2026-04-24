<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use ReflectionClass;
use ReflectionMethod;

/**
 * Resolves which OpenAPI spec name a test case should validate against.
 *
 * The resolver itself has three layers; framework adapters (e.g. the Laravel
 * `ValidatesOpenApiSchema` trait) typically split the last one into two by
 * overriding `openApiSpecFallback()`. Together they form the four-layer
 * priority documented in README (highest first; first match wins):
 *
 *   1. Method-level `#[OpenApiSpec]` attribute on the running test method.
 *   2. Class-level `#[OpenApiSpec]` attribute on the test class.
 *   3. `openApiSpec()` override (host test class overrides the adapter hook).
 *   4. Adapter default — e.g. `config('openapi-contract-testing.default_spec')`
 *      via the Laravel trait's `openApiSpec()` implementation.
 *
 * Attribute layers return the attribute's raw `name` as-is. `#[OpenApiSpec('')]`
 * is still "set" and short-circuits resolution to the empty string — the
 * consumer treats that as an error with a helpful message (see
 * `ValidatesOpenApiSchema::assertResponseMatchesOpenApiSchema`).
 */
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
