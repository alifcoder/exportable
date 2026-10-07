<?php

declare(strict_types=1);

namespace Alif\Export;

use Alif\Export\Auth\LaravelExportAuth;
use Alif\Export\Contracts\ExportAuth;
use Alif\Export\Models\DataExport;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ExportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/export.php', 'export');

        $this->app->singleton(ExportRegistry::class);
        $this->app->bind(ExportAuth::class, LaravelExportAuth::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'export');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/export.php' => config_path('export.php')], 'export-config');
            $this->publishes([
                __DIR__.'/../database/migrations/create_data_exports_table.php.stub' => database_path(
                    'migrations/'.date('Y_m_d_His').'_create_data_exports_table.php',
                ),
            ], 'export-migrations');
        }

        if (config('export.routes.enabled')) {
            Route::prefix((string) config('export.routes.prefix'))
                ->middleware((array) config('export.routes.middleware', []))
                ->group(__DIR__.'/../routes/export.php');
        }

        if (config('export.prune.schedule')) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('model:prune', ['--model' => [DataExport::class]])->hourly()->withoutOverlapping();
            });
        }
    }
}
