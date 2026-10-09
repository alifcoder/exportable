<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Feature;

use Alif\Export\Contracts\ExportFileStore;
use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Enums\ExportFormat;
use Alif\Export\Events\ExportFinished;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportQuota;
use Alif\Export\Helpers\ExportRegistry;
use Alif\Export\Jobs\RunExport;
use Alif\Export\Services\Interfaces\ExportServiceInterface;
use Alif\Export\Tests\Concerns\MakesTasks;
use Alif\Export\Tests\Fixtures\DiskFileStore;
use Alif\Export\Tests\Fixtures\Order;
use Alif\Export\Tests\Fixtures\OrderExportable;
use Alif\Export\Tests\Fixtures\OrderFilter;
use Alif\Export\Tests\Fixtures\OrderLine;
use Alif\Export\Tests\Fixtures\User;
use Alif\Export\Tests\TestCase;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class RunExportJobTest extends TestCase
{
    use MakesTasks;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        app(ExportRegistry::class)->register('orders', OrderExportable::class);
        Gate::define('data-export', fn (): bool => true);
        $this->user = User::create(['name' => 'u']);
    }

    protected function tearDown(): void
    {
        DiskFileStore::$failPut = false;
        OrderFilter::$scopeByAuthUser = false;

        parent::tearDown();
    }

    private function runTask(ExportTask $task): void
    {
        app()->call([new RunExport($task->toArray()), 'handle']);
    }

    /** @return list<ExportFinished> */
    private function finishedEvents(): array
    {
        return Event::dispatched(ExportFinished::class)->map(fn (array $args): ExportFinished => $args[0])->values()->all();
    }

    private function reserve(ExportTask $task): void
    {
        app(ExportQuota::class)->reserve($task->ownerId, $task->id);
    }

    private function slotTaken(ExportTask $task): bool
    {
        return Cache::has("export-slot:{$task->ownerId}:{$task->id}");
    }

    public function test_job_is_queued_with_a_single_try_the_configured_timeout_queue_and_connection(): void
    {
        config(['export.queue.timeout' => 321, 'export.queue.name' => 'exports', 'export.queue.connection' => 'sync']);

        $job = new RunExport([]);

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertSame(1, $job->tries);
        $this->assertSame(321, $job->timeout);
        $this->assertSame('exports', $job->queue);
        $this->assertSame('sync', $job->connection);
    }

    public function test_dispatching_pushes_the_task_payload(): void
    {
        Queue::fake();
        $task = $this->makeTask($this->user);

        RunExport::dispatch($task->toArray());

        Queue::assertPushed(RunExport::class, fn (RunExport $j): bool => $j->task === $task->toArray());
    }

    public function test_success_stores_the_file_and_announces_it_once(): void
    {
        Event::fake([ExportFinished::class]);
        Order::create(['number' => 'A', 'total' => 1]);

        $this->runTask($this->makeTask($this->user));

        $events = $this->finishedEvents();
        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->succeeded());
        $this->assertSame(1, $events[0]->rows);
        $this->assertStringEndsWith('.csv', (string) $events[0]->fileName);
        $this->assertStringEndsWith((string) $events[0]->fileName, (string) $events[0]->fileId);
        Storage::disk('local')->assertExists($events[0]->fileId);
    }

    public function test_xlsx_file_has_the_xlsx_extension(): void
    {
        Event::fake([ExportFinished::class]);

        $this->runTask($this->makeTask($this->user, format: ExportFormat::XLSX));

        $this->assertStringEndsWith('.xlsx', (string) $this->finishedEvents()[0]->fileName);
    }

    public function test_the_event_can_be_switched_off_by_config(): void
    {
        Event::fake([ExportFinished::class]);
        config(['export.events.finished' => false]);

        $this->runTask($this->makeTask($this->user));
        $this->runTask($this->makeTask($this->user, ['removed_column']));

        Event::assertNotDispatched(ExportFinished::class);
    }

    public function test_a_column_removed_from_the_definition_is_written_as_an_empty_column(): void
    {
        Event::fake([ExportFinished::class]);
        Order::create(['number' => 'A-1', 'total' => 1]);

        $this->runTask($this->makeTask($this->user, ['number', 'removed_column']));

        $event = $this->finishedEvents()[0];
        $this->assertTrue($event->succeeded());
        $this->assertStringContainsString("A-1,\n", Storage::disk('local')->get($event->fileId));
    }

    public function test_forbidden_owner_fails_with_forbidden_and_leaves_no_file(): void
    {
        Event::fake([ExportFinished::class]);
        Gate::define('data-export', fn (): bool => false);

        $this->runTask($this->makeTask($this->user));

        $this->assertSame('forbidden', $this->finishedEvents()[0]->errorCode);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_missing_owner_fails_with_owner_missing_and_is_reported(): void
    {
        Event::fake([ExportFinished::class]);
        Exceptions::fake();
        $task = $this->makeTask($this->user);
        User::query()->delete();

        $this->runTask($task);

        $this->assertSame('owner_missing', $this->finishedEvents()[0]->errorCode);
        Exceptions::assertReported(fn (ExportException $e): bool => $e->errorCode === 'owner_missing');
    }

    public function test_unexpected_exception_becomes_export_failed_and_is_reported(): void
    {
        Event::fake([ExportFinished::class]);
        Exceptions::fake();
        app()->bind(OrderExportable::class, fn () => throw new RuntimeException('kaboom'));

        $this->runTask($this->makeTask($this->user));

        $this->assertSame('export_failed', $this->finishedEvents()[0]->errorCode);
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'kaboom');
    }

    public function test_undeclared_relation_fails_instead_of_lazy_loading(): void
    {
        Event::fake([ExportFinished::class]);
        Exceptions::fake();
        $order = Order::create(['number' => 'A', 'total' => 1]);
        OrderLine::create(['order_id' => $order->id, 'sku' => 's1', 'qty' => 1]);

        $this->runTask($this->makeTask($this->user, ['number', 'undeclared']));

        $this->assertSame('export_failed', $this->finishedEvents()[0]->errorCode);
    }

    public function test_row_limit_growth_after_submit_fails_and_keeps_no_file(): void
    {
        Event::fake([ExportFinished::class]);
        config(['export.max_rows.csv' => 1]);
        Order::create(['number' => 'A', 'total' => 1]);
        Order::create(['number' => 'B', 'total' => 1]);

        $this->runTask($this->makeTask($this->user));

        $this->assertSame('row_limit_exceeded', $this->finishedEvents()[0]->errorCode);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_store_that_cannot_keep_the_file_fails_with_storage_write_failed_and_is_reported(): void
    {
        Event::fake([ExportFinished::class]);
        Exceptions::fake();
        DiskFileStore::$failPut = true;
        Order::create(['number' => 'A', 'total' => 1]);

        $this->runTask($this->makeTask($this->user));

        $this->assertSame('storage_write_failed', $this->finishedEvents()[0]->errorCode);
        Exceptions::assertReported(fn (ExportException $e): bool => $e->errorCode === 'storage_write_failed');
    }

    public function test_the_local_temporary_file_is_always_removed(): void
    {
        Order::create(['number' => 'A', 'total' => 1]);
        $before = glob(sys_get_temp_dir().'/export_*') ?: [];

        $this->runTask($this->makeTask($this->user));
        DiskFileStore::$failPut = true;
        $this->runTask($this->makeTask($this->user));

        $this->assertSame($before, glob(sys_get_temp_dir().'/export_*') ?: []);
    }

    public function test_a_throwing_listener_never_fails_the_job_and_is_reported(): void
    {
        Exceptions::fake();
        Event::listen(ExportFinished::class, fn () => throw new RuntimeException('mail down'));
        Order::create(['number' => 'A', 'total' => 1]);

        $this->runTask($this->makeTask($this->user));

        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'mail down');
    }

    public function test_job_runs_as_owner_so_host_scope_applies_and_does_not_leak_the_owner(): void
    {
        Event::fake([ExportFinished::class]);
        OrderFilter::$scopeByAuthUser = true;
        $other = User::create(['name' => 'o']);
        Order::create(['number' => 'MINE-1', 'total' => '1', 'owner' => $this->user->id]);
        Order::create(['number' => 'THEIRS-1', 'total' => '3', 'owner' => $other->id]);
        Auth::forgetUser();

        $this->runTask($this->makeTask($this->user));

        $event = $this->finishedEvents()[0];
        $csv = Storage::disk('local')->get($event->fileId);
        $this->assertStringContainsString('MINE-1', $csv);
        $this->assertStringNotContainsString('THEIRS-1', $csv);
        $this->assertSame(1, $event->rows);
        $this->assertNull(auth()->user());
    }

    public function test_children_are_flattened_with_repeated_document_cells(): void
    {
        Event::fake([ExportFinished::class]);
        $a = Order::create(['number' => 'A-1', 'total' => '10.5']);
        Order::create(['number' => 'B-1', 'total' => '5']);
        foreach (['s1', 's2'] as $sku) {
            OrderLine::create(['order_id' => $a->id, 'sku' => $sku, 'qty' => 2]);
        }

        $this->runTask($this->makeTask($this->user, ['number'], children: true));

        $event = $this->finishedEvents()[0];
        $this->assertSame(3, $event->rows);
        $this->assertSame(2, substr_count(Storage::disk('local')->get($event->fileId), 'A-1'));
    }

    public function test_the_slot_is_released_once_even_if_failed_is_called_afterwards(): void
    {
        Event::fake([ExportFinished::class]);
        Exceptions::fake();
        $task = $this->makeTask($this->user);
        $other = new ExportTask('00000000-0000-4000-8000-000000000002', $task->ownerId, 'en', $task->request, null);
        $this->reserve($task);
        $this->reserve($other);

        $job = new RunExport($task->toArray());
        app()->call([$job, 'handle']);
        $job->failed(new RuntimeException('late'));

        $this->assertFalse($this->slotTaken($task));
        $this->assertTrue($this->slotTaken($other), 'releasing one task must not touch another slot');
        $this->assertCount(1, $this->finishedEvents(), 'the owner is told once');
    }

    public function test_failed_hook_announces_a_failure_and_frees_the_slot(): void
    {
        Event::fake([ExportFinished::class]);
        Exceptions::fake();
        $task = $this->makeTask($this->user);
        $this->reserve($task);

        (new RunExport($task->toArray()))->failed(new RuntimeException('killed'));

        $this->assertSame('export_failed', $this->finishedEvents()[0]->errorCode);
        $this->assertFalse($this->slotTaken($task));
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_with_a_sync_queue_the_slot_taken_by_start_is_released_by_the_job(): void
    {
        config(['export.queue.connection' => 'sync', 'export.max_active_per_user' => 1]);
        $this->app->bind(ExportFileStore::class, DiskFileStore::class);
        Gate::define('data-export', fn (): bool => true);
        Order::create(['number' => 'A-1', 'total' => 1]);
        $service = app(ExportServiceInterface::class);
        $dto = new ExportCreateDTO('orders', ExportFormat::CSV, ['number'], false, [], null, []);

        // The job runs inside create(); a second export must not be refused by a leaked slot.
        $first = $service->create($this->user, $dto);
        $second = $service->create($this->user, $dto);

        $this->assertFalse($this->slotTaken($first));
        $this->assertFalse($this->slotTaken($second));
    }
}
