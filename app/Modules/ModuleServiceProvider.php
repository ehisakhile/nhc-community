<?php

namespace App\Modules;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Loads every module in config/modules.php: routes under /api, and commands. */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class, fn () => new ModuleRegistry(config('modules', [])));
    }

    public function boot(ModuleRegistry $modules): void
    {
        foreach ($modules->all() as $module) {
            if ($this->app->runningInConsole() && $module->commands()) {
                $this->commands($module->commands());
            }

            if (! $this->app->routesAreCached() && ($routes = $module->routes())) {
                Route::prefix('api')->middleware('api')->group($routes);
            }
        }
    }
}
