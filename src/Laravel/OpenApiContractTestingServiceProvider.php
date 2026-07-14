<?php

declare(strict_types=1);

namespace Studio\Gesso\Laravel;

use Illuminate\Support\ServiceProvider;
use Studio\Gesso\Laravel\Commands\OpenApiRoutesCommand;

class OpenApiContractTestingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config.php', 'openapi-contract-testing');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/config.php' => config_path('openapi-contract-testing.php'),
        ], 'openapi-contract-testing');

        if ($this->app->runningInConsole()) {
            $this->commands([OpenApiRoutesCommand::class]);
        }
    }
}
