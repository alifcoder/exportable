<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Infra;

use Alif\Export\Entities\DataExport;
use Alif\Export\Enums\ExportStatus;
use Alif\Export\Helpers\ExportRegistry;
use Alif\Export\Jobs\RunExport;
use Alif\Export\Tests\Fixtures\Order;
use Alif\Export\Tests\Fixtures\OrderExportable;
use Alif\Export\Tests\Fixtures\User;
use Alif\Export\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;

/**
 * Real S3 (s3mock) and real Redis queue. Not part of the default run:
 *   TEST_INFRA=1 TEST_DB_HOST=127.0.0.1 TEST_DB_PORT=55432 vendor/bin/phpunit --group infra
 * Expects S3 at 127.0.0.1:59090 (bucket export-test) and Redis at 127.0.0.1:56379.
 */
#[Group('infra')]
final class InfraTest extends TestCase
{
    private User $user;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('filesystems.disks.s3test', [
            'driver' => 's3', 'key' => 'x', 'secret' => 'x', 'region' => 'us-east-1', 'bucket' => 'export-test',
            'endpoint' => 'http://127.0.0.1:59090', 'use_path_style_endpoint' => true, 'throw' => true,
        ]);
        $app['config']->set('database.redis', [
            'client' => 'phpredis',
            'options' => ['prefix' => 'exportsdk_test_'],
            'default' => ['host' => '127.0.0.1', 'port' => 56379, 'database' => 0],
        ]);
        $app['config']->set('queue.connections.redis', ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default', 'retry_after' => 2000]);
    }

    protected function setUp(): void
    {
        if (getenv('TEST_INFRA') === false) {
            $this->markTestSkipped('Set TEST_INFRA=1 (and start the docker stack) to run.');
        }

        parent::setUp();

        // Only ever flush the isolated test Redis.
        $this->assertSame(56379, (int) config('database.redis.default.port'));
        Redis::connection()->flushdb();

        app(ExportRegistry::class)->register('orders', OrderExportable::class);
        Gate::define('data-export', fn (): bool => true);
        $this->user = User::create(['name' => 'u']);
        Order::create(['number' => 'A-1', 'total' => '10.5']);
        Order::create(['number' => 'B-2', 'total' => '5']);
    }

    protected function tearDown(): void
    {
        if (getenv('TEST_INFRA') !== false) {
            Storage::disk('s3test')->deleteDirectory('exports');
            Redis::connection()->flushdb();
        }

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function payload(string $format): array
    {
        return ['exportable' => 'orders', 'file' => ['format' => $format, 'columns' => ['number', 'total']]];
    }

    public function test_files_roundtrip_through_a_real_s3_disk(): void
    {
        config(['export.disk' => 's3test']);

        foreach (['csv', 'xlsx'] as $format) {
            $id = $this->actingAs($this->user)->postJson('/exports', $this->payload($format))->assertStatus(202)->json('data.id');
            $export = DataExport::findOrFail($id);

            $this->assertSame(ExportStatus::COMPLETED, $export->status, (string) $export->error_code);
            $this->assertSame('s3test', $export->disk);
            $this->assertTrue(Storage::disk('s3test')->exists($export->path), "{$format} object missing in S3");

            $download = $this->actingAs($this->user)->get("/exports/{$id}/download")->assertOk();
            $body = $download->streamedContent();
            $this->assertSame(Storage::disk('s3test')->size($export->path), strlen($body));

            if ($format === 'csv') {
                $this->assertStringContainsString('Number,Total', $body);
                $this->assertStringContainsString('A-1', $body);
            }
            if ($format === 'xlsx') {
                $this->assertStringStartsWith('PK', $body);
            }

            $this->actingAs($this->user)->deleteJson("/exports/{$id}")->assertNoContent();
            $this->assertFalse(Storage::disk('s3test')->exists($export->path), "{$format} object not removed from S3");
        }
    }

    public function test_job_goes_through_the_real_redis_queue_and_a_duplicate_delivery_is_a_noop(): void
    {
        config(['queue.default' => 'redis', 'export.queue.name' => 'exports', 'export.queue.connection' => 'redis']);
        Storage::fake('local');

        $id = $this->actingAs($this->user)->postJson('/exports', $this->payload('csv'))->assertStatus(202)->json('data.id');

        $this->assertSame(ExportStatus::PENDING, DataExport::findOrFail($id)->status);
        $this->assertSame(1, Queue::connection('redis')->size('exports'), 'job should be waiting in Redis');

        Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'exports', '--once' => true]);

        $first = DataExport::findOrFail($id);
        $this->assertSame(ExportStatus::COMPLETED, $first->status, (string) $first->error_code);
        $this->assertSame(0, Queue::connection('redis')->size('exports'));

        // Redelivery of the same job: must not run a second time or change the file.
        RunExport::dispatch($id);
        Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'exports', '--once' => true]);

        $second = DataExport::findOrFail($id);
        $this->assertSame($first->path, $second->path);
        $this->assertSame($first->finished_at?->toIso8601String(), $second->finished_at?->toIso8601String());
        $this->assertCount(1, Storage::disk('local')->allFiles('exports'));
    }

    public function test_a_failing_job_on_the_real_queue_marks_the_row_failed_without_retrying(): void
    {
        config(['queue.default' => 'redis', 'export.queue.name' => 'exports', 'export.queue.connection' => 'redis']);
        Storage::fake('local');

        $id = $this->actingAs($this->user)->postJson('/exports', [
            'exportable' => 'orders', 'file' => ['format' => 'csv', 'columns' => ['undeclared']],
        ])->assertStatus(202)->json('data.id');

        Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'exports', '--once' => true]);

        $export = DataExport::findOrFail($id);
        $this->assertSame(ExportStatus::FAILED, $export->status);
        $this->assertSame(0, Queue::connection('redis')->size('exports'), 'tries=1: no redelivery');
        $this->assertSame([], Storage::disk('local')->allFiles('exports'));
    }
}
