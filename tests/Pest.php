<?php

declare(strict_types=1);

use Studio\Gesso\Tests\Helpers\PestLaravelTestCase;

/*
|--------------------------------------------------------------------------
| Pest configuration
|--------------------------------------------------------------------------
|
| Loaded by the Pest CLI before any tests run. Wires the Pest tests under
| tests/Integration/Pest/ to the Orchestra Testbench harness in
| tests/Helpers/PestLaravelTestCase.php so each `it(...)` block inherits
| Laravel routes, the spec base path, and the ValidatesOpenApiSchema trait
| (via the base class).
|
| `composer test:pest` runs `pest --colors=always tests/Integration/Pest`
| (the path is baked into the script), so this file does not need to
| filter directories — the script's path argument already does.
*/

// Bind the Orchestra Testbench harness explicitly to the Laravel-flavoured
// test files. NegativePathsTest covers boundary errors that can be triggered
// without booting Laravel, and MissingTraitTest deliberately runs against
// vanilla PHPUnit\Framework\TestCase to exercise the dispatch's "missing
// trait" guidance message — both are excluded so they don't pick up the
// trait that would short-circuit those checks.
uses(PestLaravelTestCase::class)->in(
    'Integration/Pest/PluginLoadsTest.php',
    'Integration/Pest/ExpectationsTest.php',
);
