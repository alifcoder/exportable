<?php

declare(strict_types=1);

namespace Alif\Export\Tests\EndToEnd;

use Alif\Export\Contracts\ExportFileStore;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Events\ExportFinished;
use Alif\Export\Events\ExportProgressed;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Providers\ExportServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Throwable;

/**
 * Runs the whole flow on PostgreSQL (the only supported driver) with a synchronous queue: `export()` validates and
 * queues, the job writes the file at once, and a fake file store keeps what it was given. Skipped when no database
 * answers (set EXPORT_TEST_PGSQL_HOST / _PORT / _DATABASE / _USERNAME / _PASSWORD).
 */
require_once __DIR__.'/Models.php';

abstract class EndToEndTestCase extends Orchestra
{
    /** @var list<ExportFinished> */
    protected array $finished = [];

    /** @var list<ExportProgressed> */
    protected array $progress = [];

    /** @var array<string, string> File id => bytes. */
    public static array $files = [];

    public static bool $storeFails = false;

    private bool $connected = false;

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [ExportServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => getenv('EXPORT_TEST_PGSQL_HOST') ?: '127.0.0.1',
            'port' => getenv('EXPORT_TEST_PGSQL_PORT') ?: '54329',
            'database' => getenv('EXPORT_TEST_PGSQL_DATABASE') ?: 'export_test',
            'username' => getenv('EXPORT_TEST_PGSQL_USERNAME') ?: 'postgres',
            'password' => getenv('EXPORT_TEST_PGSQL_PASSWORD') ?: '',
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
        ]);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('export', require __DIR__.'/../../config/export.php');
        $app['config']->set('export.queue.name', null);
        $app['config']->set('app.timezone', 'UTC');
        $app['config']->set('app.locale', 'en');
    }

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->app['db']->connection()->getPdo();
            $this->connected = true;
        } catch (Throwable $e) {
            $this->markTestSkipped('No PostgreSQL for the end-to-end tests: '.$e->getMessage());
        }

        self::$files = [];
        self::$storeFails = false;
        OrderResource::$explodeAt = null;
        OrderResource::$extra = [];
        OrderFilter::$declared = [];
        OrderFilter::$sort = ['id'];
        OrderFilter::$grouped = false;
        OrderFilter::$union = false;
        Cache::flush();

        $this->app->bind(ExportFileStore::class, FakeStore::class);

        Event::listen(ExportFinished::class, fn (ExportFinished $e) => $this->finished[] = $e);
        Event::listen(ExportProgressed::class, fn (ExportProgressed $e) => $this->progress[] = $e);

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        if ($this->connected) {
            foreach (['e2e_serials', 'e2e_lines', 'e2e_orders', 'e2e_partners'] as $table) {
                Schema::dropIfExists($table);
            }
        }

        parent::tearDown();
    }

    private function createSchema(): void
    {
        foreach (['e2e_serials', 'e2e_lines', 'e2e_orders', 'e2e_partners'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('e2e_partners', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });
        Schema::create('e2e_orders', function (Blueprint $t): void {
            $t->id();
            $t->string('number');
            $t->foreignId('partner_id')->nullable();
            $t->date('document_date')->nullable();
            $t->decimal('total_amount', 18, 2)->default(0);
            $t->boolean('is_paid')->default(false);
            $t->text('comment')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
        Schema::create('e2e_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('order_id');
            $t->integer('sequence');
            $t->string('product_name');
            $t->integer('quantity');
            $t->decimal('price', 18, 2)->default(0);
        });
        Schema::create('e2e_serials', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('line_id');
            $t->string('imei');
        });
    }

    /** @param array<string, mixed> $overrides */
    protected function seedOrders(int $count, array $overrides = [], int $lines = 0, int $serials = 0): void
    {
        $partner = Partner::query()->create(['name' => 'Acme']);
        $at = '2026-03-04 05:06:07';

        for ($i = 1; $i <= $count; $i++) {
            $order = Order::query()->create([
                'number' => sprintf('ORD-%05d', $i),
                'partner_id' => $partner->id,
                'document_date' => '2026-03-04',
                'total_amount' => $i * 1.5,
                'is_paid' => $i % 2 === 0,
                'comment' => null,
                'created_at' => $at,
                'updated_at' => $at,
                ...$overrides,
            ]);

            for ($l = 1; $l <= $lines; $l++) {
                $line = Line::query()->create(['order_id' => $order->id, 'sequence' => $l, 'product_name' => "P$l", 'quantity' => $l, 'price' => 10.5 * $l]);

                for ($s = 1; $s <= $serials; $s++) {
                    Serial::query()->create(['line_id' => $line->id, 'imei' => sprintf('IMEI-%d-%d-%d', $i, $l, $s)]);
                }
            }
        }
    }

    /** @param array<string, mixed> $request */
    protected function export(array $request): ExportTask
    {
        return (new OrderFilter($request))->export();
    }

    /** Writes the stored bytes to a temp file and returns its path (PhpSpreadsheet reads files). */
    protected function tempFile(string $bytes, string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'e2e_').'.'.$extension;
        file_put_contents($path, $bytes);

        return $path;
    }

    protected function lastFile(): string
    {
        $this->assertNotEmpty($this->finished, 'The export did not finish.');
        $event = end($this->finished);
        $this->assertTrue($event->succeeded(), 'Export failed: '.$event->errorCode);

        return self::$files[$event->fileId];
    }
}

/** Keeps what the job gives it in memory. */
final class FakeStore implements ExportFileStore
{
    public function put(ExportTask $task, string $localPath, string $fileName, string $mimeType): string
    {
        if (EndToEndTestCase::$storeFails) {
            throw ExportException::storageWriteFailed('disk full');
        }

        $id = $task->id.'|'.$fileName.'|'.$mimeType;
        EndToEndTestCase::$files[$id] = (string) file_get_contents($localPath);

        return $id;
    }
}
