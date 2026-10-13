<?php

declare(strict_types=1);

namespace Alif\Export\Providers;

use Alif\Export\Contracts\ExportFileStore;
use Alif\Export\Services\ExportService;
use Alif\Export\Services\Interfaces\ExportServiceInterface;
use Illuminate\Support\ServiceProvider;

/**
 * Registers no routes, no tables, no schedule and no config defaults. The host provides `config/export.php` (see
 * the published stub), its own endpoints and permission checks over {@see ExportServiceInterface}, an
 * implementation of {@see ExportFileStore}.
 */
final class ExportServiceProvider extends ServiceProvider
{
    /** Binds the export service interface to its implementation. */
    public function register(): void
    {
        $this->app->bind(ExportServiceInterface::class, ExportService::class);
    }

    /** Loads the package's messages (`export::messages.*`) and lets the host publish the config stub with `export-config`. */
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'export');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../../config/export.php' => config_path('export.php')], 'export-config');
            $this->publishes([__DIR__.'/../../lang' => lang_path('vendor/export')], 'export-lang');
        }
    }
}
