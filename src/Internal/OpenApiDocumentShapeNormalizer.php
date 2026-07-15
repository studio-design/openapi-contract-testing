<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use stdClass;
use Studio\Gesso\Spec\OpenApiOperationResolver;

use function get_object_vars;
use function in_array;
use function is_array;
use function is_string;

/**
 * Restores PHP's historical array representation after parsing object maps,
 * while retaining the one empty-object distinction required by OpenAPI:
 * an empty Security Requirement Object means that anonymous access is allowed.
 *
 * This must run after reference resolution. An external document may contain
 * only a Schema Object, so its decoder cannot know whether a key named
 * `security` is an Operation Object field or a user-defined schema property.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class OpenApiDocumentShapeNormalizer
{
    private function __construct() {}

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    public static function normalizeResolvedDocument(array $document): array
    {
        $normalized = [];

        foreach ($document as $key => $value) {
            $normalized[$key] = match ($key) {
                'security' => self::normalizeSecurityRequirements($value),
                'paths', 'webhooks' => self::normalizePathItemMap($value),
                'components' => self::normalizeComponents($value),
                default => self::normalizeGeneric($value),
            };
        }

        return $normalized;
    }

    private static function normalizeComponents(mixed $components): mixed
    {
        if (!is_array($components)) {
            return self::normalizeGeneric($components);
        }

        $normalized = [];
        foreach ($components as $key => $value) {
            $normalized[$key] = match ($key) {
                'pathItems' => self::normalizePathItemMap($value),
                'callbacks' => self::normalizeCallbackMap($value),
                default => self::normalizeGeneric($value),
            };
        }

        return $normalized;
    }

    private static function normalizePathItemMap(mixed $pathItems): mixed
    {
        if (!is_array($pathItems)) {
            return self::normalizeGeneric($pathItems);
        }

        $normalized = [];
        foreach ($pathItems as $name => $pathItem) {
            $normalized[$name] = is_array($pathItem)
                ? self::normalizePathItem($pathItem)
                : self::normalizeGeneric($pathItem);
        }

        return $normalized;
    }

    /**
     * @param array<int|string, mixed> $pathItem
     *
     * @return array<int|string, mixed>
     */
    private static function normalizePathItem(array $pathItem): array
    {
        $normalized = [];

        foreach ($pathItem as $key => $value) {
            if (is_string($key) && in_array($key, OpenApiOperationResolver::FIXED_OPERATION_FIELDS, true)) {
                $normalized[$key] = is_array($value)
                    ? self::normalizeOperation($value)
                    : self::normalizeGeneric($value);

                continue;
            }

            $normalized[$key] = $key === 'additionalOperations'
                ? self::normalizeOperationMap($value)
                : self::normalizeGeneric($value);
        }

        return $normalized;
    }

    private static function normalizeOperationMap(mixed $operations): mixed
    {
        if (!is_array($operations)) {
            return self::normalizeGeneric($operations);
        }

        $normalized = [];
        foreach ($operations as $method => $operation) {
            $normalized[$method] = is_array($operation)
                ? self::normalizeOperation($operation)
                : self::normalizeGeneric($operation);
        }

        return $normalized;
    }

    /**
     * @param array<int|string, mixed> $operation
     *
     * @return array<int|string, mixed>
     */
    private static function normalizeOperation(array $operation): array
    {
        $normalized = [];

        foreach ($operation as $key => $value) {
            $normalized[$key] = match ($key) {
                'security' => self::normalizeSecurityRequirements($value),
                'callbacks' => self::normalizeCallbackMap($value),
                default => self::normalizeGeneric($value),
            };
        }

        return $normalized;
    }

    private static function normalizeCallbackMap(mixed $callbacks): mixed
    {
        if (!is_array($callbacks)) {
            return self::normalizeGeneric($callbacks);
        }

        $normalized = [];
        foreach ($callbacks as $name => $callback) {
            $normalized[$name] = self::normalizeCallback($callback);
        }

        return $normalized;
    }

    private static function normalizeCallback(mixed $callback): mixed
    {
        if (!is_array($callback)) {
            return self::normalizeGeneric($callback);
        }

        $normalized = [];
        foreach ($callback as $expression => $pathItem) {
            $normalized[$expression] = is_array($pathItem)
                ? self::normalizePathItem($pathItem)
                : self::normalizeGeneric($pathItem);
        }

        return $normalized;
    }

    private static function normalizeSecurityRequirements(mixed $requirements): mixed
    {
        // Preserve an empty object at the field boundary. Only an empty object
        // *inside* a valid security list means anonymous access; `security: {}`
        // is a malformed container and must reach SecurityValidator as an
        // object so its list-shape guard can reject it.
        if (self::isEmptyObject($requirements)) {
            return $requirements;
        }

        if (!is_array($requirements)) {
            return self::normalizeGeneric($requirements);
        }

        $normalized = [];
        foreach ($requirements as $key => $requirement) {
            $normalized[$key] = self::isEmptyObject($requirement)
                ? $requirement
                : self::normalizeGeneric($requirement);
        }

        return $normalized;
    }

    private static function normalizeGeneric(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $child) {
            $normalized[$key] = self::normalizeGeneric($child);
        }

        return $normalized;
    }

    private static function isEmptyObject(mixed $value): bool
    {
        return $value instanceof stdClass && get_object_vars($value) === [];
    }
}
