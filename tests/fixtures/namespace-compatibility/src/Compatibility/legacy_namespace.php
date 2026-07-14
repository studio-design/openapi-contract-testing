<?php

declare(strict_types=1);

namespace Studio\Gesso\Compatibility;

use function class_alias;
use function class_exists;
use function enum_exists;
use function interface_exists;
use function spl_autoload_register;
use function str_starts_with;
use function strlen;
use function substr;
use function trait_exists;

spl_autoload_register(static function (string $legacy): void {
    $legacyPrefix = 'Studio\\OpenApiContractTesting\\';
    if (!str_starts_with($legacy, $legacyPrefix)) {
        return;
    }

    $canonical = 'Studio\\Gesso\\' . substr($legacy, strlen($legacyPrefix));
    if (!class_exists($canonical) &&
        !interface_exists($canonical) &&
        !trait_exists($canonical) &&
        !enum_exists($canonical)) {
        return;
    }

    class_alias($canonical, $legacy);
});
