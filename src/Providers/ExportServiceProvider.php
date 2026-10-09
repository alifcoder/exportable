<?php

declare(strict_types=1);

namespace Alif\Export\Providers;

use Alif\Export\Contracts\ExportFileStore;
use Alif\Export\Helpers\ExportRegistry;
use Alif\Export\Services\ExportService;
use Alif\Export\Services\Interfaces\ExportServiceInterface;
use Illuminate\Support\ServiceProvider;

/**
 * Registers no routes, no tables, no schedule and no config defaults. The host provides `config/export.php` (see
 * the published stub), its own endpoints and permission checks over {@see ExportServiceInterface}, an
 * implementation of {@see ExportFileStore} and, in `export.queue.middleware`, the job middleware that signs the
 * owner in. To change which documents exist, bind a subclass of {@see ExportRegistry}.
 */
final class ExportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ExportRegistry::class);
        $this->app->bind(ExportServiceInterface::class, ExportService::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../../config/export.php' => config_path('export.php')], 'export-config');
        }
    }
}
