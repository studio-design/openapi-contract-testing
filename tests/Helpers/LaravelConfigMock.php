<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Laravel;

use Throwable;

use function array_key_exists;
use function function_exists;

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
 * 2. Otherwise, defer to the real framework helper when an app is running
 *    (integration tests using orchestra/testbench). The global helper is
 *    invoked via a variable function to bypass both PHP namespace resolution
 *    (which would recurse into this mock) and cs-fixer's global_namespace_import
 *    rule (which would strip a leading backslash and break the call).
 */
function config(string $key, mixed $default = null): mixed
{
    if (
        isset($GLOBALS['__openapi_testing_config']) &&
        array_key_exists($key, $GLOBALS['__openapi_testing_config'])
    ) {
        return $GLOBALS['__openapi_testing_config'][$key];
    }

    if (function_exists('config')) {
        $globalConfig = 'config';

        try {
            return $globalConfig($key, $default);
        } catch (Throwable) {
            return $default;
        }
    }

    return $default;
}
