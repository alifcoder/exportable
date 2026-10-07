<?php

declare(strict_types=1);

namespace Alif\Export\Tests;

use Alif\Export\ExportServiceProvider;
use Alif\Export\Tests\Fixtures\User;
use Barryvdh\DomPDF\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\ExcelServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ExportServiceProvider::class, ExcelServiceProvider::class, ServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('filesystems.default', 'local');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('export.routes.middleware', ['api', 'auth:web']);
        $app['config']->set('export.guard', 'web');
        $app['config']->set('export.queue.name', null);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($t): void {
            $t->uuid('id')->primary();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('orders', function ($t): void {
            $t->uuid('id')->primary();
            $t->string('number');
            $t->decimal('total', 20, 6)->default(0);
            $t->string('owner')->nullable();
            $t->timestamps();
        });
        Schema::create('order_lines', function ($t): void {
            $t->uuid('id')->primary();
            $t->uuid('order_id')->index();
            $t->string('sku');
            $t->integer('qty')->default(1);
            $t->timestamps();
        });

        $migration = include __DIR__.'/../database/migrations/create_data_exports_table.php.stub';
        $migration->up();
    }
}
