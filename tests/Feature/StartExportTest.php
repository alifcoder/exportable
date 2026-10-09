<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Feature;

use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\Enums\ExportFormat;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportQuota;
use Alif\Export\Helpers\ExportRegistry;
use Alif\Export\Jobs\RunExport;
use Alif\Export\Services\Interfaces\ExportServiceInterface;
use Alif\Export\Tests\Fixtures\Order;
use Alif\Export\Tests\Fixtures\OrderExportable;
use Alif\Export\Tests\Fixtures\OrderLine;
use Alif\Export\Tests\Fixtures\User;
use Alif\Export\Tests\TestCase;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class StartExportTest extends TestCase
{
    private User $user;

    private ExportServiceInterface $service;

    protected function setUp(): void
    {
        parent::setUp();

        app(ExportRegistry::class)->register('orders', OrderExportable::class);
        $this->user = User::create(['name' => 'u']);
        $this->service = app(ExportServiceInterface::class);
    }

    private function dto(bool $children = false, string $key = 'orders'): ExportCreateDTO
    {
        return new ExportCreateDTO($key, ExportFormat::CSV, ['number'], $children, $children ? ['sku'] : [], null, []);
    }

    private function slots(?User $owner = null): int
    {
        $ownerId = ($owner ?? $this->user)->getKey();

        return count(array_filter(
            (array) Cache::get("export-slots:{$ownerId}", []),
            fn (string $id): bool => Cache::has("export-slot:{$ownerId}:{$id}"),
        ));
    }

    public function test_an_export_that_selects_nothing_is_refused_before_a_job_is_queued(): void
    {
        Queue::fake();
        Order::query()->delete();

        try {
            $this->service->create($this->user, $this->dto());
            $this->fail('An empty export must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data', $e->errors());
        }

        Queue::assertNothingPushed();
        $this->assertSame(0, $this->slots(), 'the slot is freed');
    }

    public function test_definition_returns_the_exportable_and_rejects_an_unknown_key(): void
    {
        $this->assertInstanceOf(OrderExportable::class, $this->service->definition('orders'));

        try {
            $this->service->definition('nope');
            $this->fail('expected unknown_exportable');
        } catch (ExportException $e) {
            $this->assertSame('unknown_exportable', $e->errorCode);
        }
    }

    public function test_create_queues_a_task_with_owner_locale_request_and_row_total(): void
    {
        Queue::fake();
        config(['export.progress.enabled' => true]);
        app()->setLocale('uz');
        Order::create(['number' => 'A', 'total' => 1]);
        Order::create(['number' => 'B', 'total' => 1]);

        $task = $this->service->create($this->user, $this->dto());

        $this->assertSame((string) $this->user->getKey(), $task->ownerId);
        $this->assertSame('uz', $task->locale);
        $this->assertSame(2, $task->totalRows);
        Queue::assertPushed(RunExport::class, fn (RunExport $j): bool => $j->task === $task->toArray());
    }

    public function test_the_row_total_is_counted_only_when_progress_is_on(): void
    {
        Queue::fake();
        Order::create(['number' => 'A', 'total' => 1]);
        config(['export.progress.enabled' => false]);

        $this->assertNull($this->service->create($this->user, $this->dto())->totalRows);
    }

    public function test_quota_refuses_the_export_over_the_limit_and_keeps_the_slots(): void
    {
        Order::create(['number' => 'A', 'total' => 1]);
        Queue::fake();
        config(['export.max_active_per_user' => 2]);

        $this->service->create($this->user, $this->dto());
        $this->service->create($this->user, $this->dto());

        try {
            $this->service->create($this->user, $this->dto());
            $this->fail('expected too_many_active');
        } catch (ExportException $e) {
            $this->assertSame('too_many_active', $e->errorCode);
        }
        $this->assertSame(2, $this->slots());
        Queue::assertPushed(RunExport::class, 2);
    }

    public function test_quota_is_per_owner(): void
    {
        Order::create(['number' => 'A', 'total' => 1]);
        Queue::fake();
        config(['export.max_active_per_user' => 1]);

        $this->service->create($this->user, $this->dto());
        $this->service->create(User::create(['name' => 'o']), $this->dto());

        Queue::assertPushed(RunExport::class, 2);
    }

    public function test_a_zero_quota_always_refuses(): void
    {
        config(['export.max_active_per_user' => 0]);

        $this->expectException(ExportException::class);

        $this->service->create($this->user, $this->dto());
    }

    public function test_a_slot_expires_after_timeout_plus_margin(): void
    {
        Order::create(['number' => 'A', 'total' => 1]);
        Queue::fake();
        config(['export.queue.timeout' => 100, 'export.stale_margin_seconds' => 20]);

        $this->service->create($this->user, $this->dto());
        $this->assertSame(1, $this->slots());

        $this->travel(121)->seconds();

        $this->assertSame(0, $this->slots());
    }

    public function test_a_failed_dispatch_frees_the_slot_and_rethrows(): void
    {
        Order::create(['number' => 'A', 'total' => 1]);
        $this->app->bind(Dispatcher::class, fn () => throw new RuntimeException('queue down'));

        try {
            $this->service->create($this->user, $this->dto());
            $this->fail('expected exception');
        } catch (RuntimeException $e) {
            $this->assertSame('queue down', $e->getMessage());
        }

        $this->assertSame(0, $this->slots());
    }

    public function test_a_rejected_request_does_not_take_a_slot(): void
    {
        Queue::fake();
        config(['export.max_rows.csv' => 1]);
        Order::create(['number' => 'A', 'total' => 1]);
        Order::create(['number' => 'B', 'total' => 1]);

        try {
            $this->service->create($this->user, $this->dto());
            $this->fail('expected validation error');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('file.format', $e->errors());
        }

        $this->assertSame(0, $this->slots());
        Queue::assertNothingPushed();
    }

    public function test_the_row_cap_counts_flattened_children_and_is_inclusive(): void
    {
        Queue::fake();
        $a = Order::create(['number' => 'A', 'total' => 1]);
        Order::create(['number' => 'B', 'total' => 1]);
        foreach (['s1', 's2', 's3'] as $sku) {
            OrderLine::create(['order_id' => $a->id, 'sku' => $sku, 'qty' => 1]);
        }

        config(['export.max_rows.csv' => 3]);
        try {
            $this->service->create($this->user, $this->dto(children: true));
            $this->fail('expected validation error');
        } catch (ValidationException) {
            // 4 flattened output rows exceed the cap although there are fewer documents.
        }

        config(['export.max_rows.csv' => 4]);
        $this->service->create($this->user, $this->dto(children: true));

        Queue::assertPushed(RunExport::class, 1);
    }

    public function test_unknown_exportable_throws(): void
    {
        $this->expectException(ExportException::class);

        $this->service->create($this->user, $this->dto(key: 'nope'));
    }

    public function test_the_row_count_is_queried_once_for_cap_and_progress(): void
    {
        Queue::fake();
        config(['export.progress.enabled' => true]);
        Order::create(['number' => 'A', 'total' => 1]);
        $queries = 0;
        \DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $before = $queries;
        $this->service->create($this->user, $this->dto());

        $this->assertSame(1, $queries - $before, 'one count query serves the cap and the progress total');
    }

    public function test_a_slow_start_does_not_drop_a_release_that_happens_meanwhile(): void
    {
        Queue::fake();
        config(['export.max_active_per_user' => 2]);
        $quota = app(ExportQuota::class);
        $owner = (string) $this->user->getKey();

        $quota->reserve($owner, 'a');
        $quota->reserve($owner, 'b');
        // A third start is slow (outside the lock): meanwhile both finish.
        $quota->release($owner, 'a');
        $quota->release($owner, 'b');
        $quota->release($owner, 'b'); // idempotent

        $quota->reserve($owner, 'c');
        $quota->reserve($owner, 'd');

        $this->assertSame(2, $this->slots());
    }

    public function test_the_owner_lock_is_free_after_reserving_so_a_nested_release_cannot_stall(): void
    {
        $quota = app(ExportQuota::class);
        $owner = (string) $this->user->getKey();

        $quota->reserve($owner, 'a');

        $this->assertTrue(Cache::lock("export-quota:{$owner}", 5)->get(), 'no lock is held between reserve and release');
    }
}
