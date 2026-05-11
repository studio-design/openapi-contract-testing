<?php

declare(strict_types=1);

use Studio\OpenApiContractTesting\Tests\Helpers\PestLaravelTestCase;

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

uses(PestLaravelTestCase::class)->in('Integration/Pest');
