<?php

declare(strict_types=1);

namespace Alif\Export\Tests;

use Alif\Export\Contracts\ExportFileStore;
use Alif\Export\Providers\ExportServiceProvider;
use Alif\Export\Tests\Fixtures\ActingAsOwner;
use Alif\Export\Tests\Fixtures\DiskFileStore;
use Alif\Export\Tests\Fixtures\User;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ExportServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');

        // Opt-in: run the suite on Postgres (TEST_DB_HOST=127.0.0.1 TEST_DB_PORT=55432 ...) instead of in-memory sqlite.
        if (getenv('TEST_DB_HOST') !== false) {
            $app['config']->set('database.default', 'pgsql');
            $app['config']->set('database.connections.pgsql', [
                'driver' => 'pgsql',
                'host' => getenv('TEST_DB_HOST'),
                'port' => getenv('TEST_DB_PORT') ?: '5432',
                'database' => getenv('TEST_DB_DATABASE') ?: 'erp_export_test',
                'username' => getenv('TEST_DB_USERNAME') ?: 'erp',
                'password' => getenv('TEST_DB_PASSWORD') ?: 'erp',
                'charset' => 'utf8',
                'search_path' => 'public',
            ]);
        }
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('filesystems.default', 'local');
        $app['config']->set('auth.providers.users.model', User::class);
        // The package ships no defaults; the tests use the published stub as the host configuration.
        $app['config']->set('export', require __DIR__.'/../config/export.php');
        $app['config']->set('export.queue.name', null);
        $app['config']->set('export.ttl_hours', 24);
        $app['config']->set('export.queue.middleware', [ActingAsOwner::class]);

        $app->bind(ExportFileStore::class, DiskFileStore::class);
    }

    protected function tearDown(): void
    {
        $this->dropPersistentTables();

        parent::tearDown();
    }

    /** A persistent (Postgres) database must not leak tables between tests, even after a crashed run. */
    private function dropPersistentTables(): void
    {
        if (config('database.default') === 'pgsql') {
            foreach (['order_lines', 'orders', 'users'] as $table) {
                Schema::dropIfExists($table);
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->dropPersistentTables();

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
    }
}
