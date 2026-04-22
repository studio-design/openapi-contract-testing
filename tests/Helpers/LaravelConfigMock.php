<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Laravel;

use Illuminate\Container\Container;

use function array_key_exists;
use function class_exists;

/**
 * Namespace-level config() mock for unit testing.
 *
 * This library does not depend on laravel/framework, so the global \config()
 * helper is unavailable during unit tests. This namespaced function acts as a
 * lightweight substitute.
 *
 * PHP resolves unqualified function calls by checking the current namespace first,
 * then falling back to the global namespace. Because the ValidatesOpenApiSchema trait
 * lives in Studio\OpenApiContractTesting\Laravel and calls config() without a leading
 * backslash, this function takes priority over any global \config() that might exist
 * at runtime.
 *
 * IMPORTANT: This relies on config() being called as an unqualified function
 * in ValidatesOpenApiSchema.php (i.e., no "use function config" import).
 * Adding such an import would bypass namespace resolution and break this mock.
 *
 * Resolution order:
 * 1. A unit-test override in $GLOBALS['__openapi_testing_config'] wins — unit
 *    tests set this explicitly to control what the trait sees.
 * 2. When a Laravel container is bootstrapped and has a "config" binding (i.e.
 *    integration tests using orchestra/testbench), delegate to the framework's
 *    config() helper via a variable function. The variable call avoids both
 *    PHP namespace resolution (which would recurse into this mock) and any
 *    future auto-import of the global \config() by cs-fixer's
 *    global_namespace_import rule (which would add a "use function config;"
 *    and re-break this mock for the same reason as a manual import).
 * 3. Otherwise (pure unit tests with no booted app), return the provided
 *    default. The container-bound check avoids swallowing real binding errors:
 *    only an unbootstrapped container falls through here.
 */
function config(string $key, mixed $default = null): mixed
{
    if (
        isset($GLOBALS['__openapi_testing_config']) &&
        array_key_exists($key, $GLOBALS['__openapi_testing_config'])
    ) {
        return $GLOBALS['__openapi_testing_config'][$key];
    }

    if (class_exists(Container::class) && Container::getInstance()->bound('config')) {
        $globalConfig = 'config';

        return $globalConfig($key, $default);
    }

    return $default;
}
