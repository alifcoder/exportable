<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Feature;

use Alif\Export\Entities\DataExport;
use Alif\Export\Enums\ExportStatus;
use Alif\Export\Events\ExportFinished;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportRegistry;
use Alif\Export\Jobs\RunExport;
use Alif\Export\Tests\Concerns\MakesExports;
use Alif\Export\Tests\Fixtures\Order;
use Alif\Export\Tests\Fixtures\OrderExportable;
use Alif\Export\Tests\Fixtures\User;
use Alif\Export\Tests\TestCase;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class RunExportJobTest extends TestCase
{
    use MakesExports;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        app(ExportRegistry::class)->register('orders', OrderExportable::class);
        Gate::define('data-export', fn ($user, string $key): bool => true);
        $this->user = User::create(['name' => 'u']);
    }

    private function runJob(DataExport $export): void
    {
        app()->call([new RunExport($export->id), 'handle']);
    }

    // ---- configuration ------------------------------------------------------

    public function test_job_is_queued_with_a_single_try_and_the_configured_timeout(): void
    {
        config(['export.queue.timeout' => 321]);

        $job = new RunExport('id');

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertSame(1, $job->tries);
        $this->assertSame(321, $job->timeout);
    }

    public function test_job_goes_to_the_configured_queue_and_connection(): void
    {
        config(['export.queue.name' => 'exports', 'export.queue.connection' => 'sync']);

        $job = new RunExport('id');

        $this->assertSame('exports', $job->queue);
        $this->assertSame('sync', $job->connection);
    }

    public function test_dispatching_pushes_the_export_id(): void
    {
        Queue::fake();

        RunExport::dispatch('abc');

        Queue::assertPushed(RunExport::class, fn (RunExport $j): bool => $j->exportId === 'abc');
    }

    // ---- handle -------------------------------------------------------------

    public function test_missing_row_is_a_silent_no_op(): void
    {
        Event::fake([ExportFinished::class]);

        app()->call([new RunExport('00000000-0000-4000-8000-000000000000'), 'handle']);

        Event::assertNotDispatched(ExportFinished::class);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_success_completes_the_row_writes_the_file_under_the_configured_directory_and_announces_once(): void
    {
        Event::fake([ExportFinished::class]);
        config(['export.directory' => '/custom/dir/']);
        Order::create(['number' => 'A', 'total' => 1]);
        $export = $this->makeExport($this->user, ['options' => ['columns' => ['number']]]);

        $this->runJob($export);

        $fresh = DataExport::findOrFail($export->id);
        $this->assertSame(ExportStatus::COMPLETED, $fresh->status);
        $this->assertSame(1, $fresh->rows_count);
        $this->assertMatchesRegularExpression('#^custom/dir/[0-9a-f-]{36}\.csv$#', $fresh->path);
        $this->assertSame('local', $fresh->disk);
        Storage::disk('local')->assertExists($fresh->path);
        Event::assertDispatchedTimes(ExportFinished::class, 1);
    }

    public function test_configured_disk_overrides_the_default_one(): void
    {
        Storage::fake('exports-disk');
        config(['export.disk' => 'exports-disk']);
        $export = $this->makeExport($this->user);

        $this->runJob($export);

        $fresh = DataExport::findOrFail($export->id);
        $this->assertSame('exports-disk', $fresh->disk);
        Storage::disk('exports-disk')->assertExists($fresh->path);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_xlsx_path_has_the_xlsx_extension(): void
    {
        $export = $this->makeExport($this->user, ['format' => 'xlsx', 'options' => ['format' => 'xlsx']]);

        $this->runJob($export);

        $this->assertStringEndsWith('.xlsx', DataExport::findOrFail($export->id)->path);
    }

    public function test_job_skips_a_cancelled_export_and_never_resurrects_it(): void
    {
        $export = $this->makeExport($this->user);
        DataExport::whereKey($export->id)->delete();

        $this->runJob($export);

        $this->assertNull(DataExport::find($export->id));
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    #[DataProvider('terminalOrBusy')]
    public function test_job_does_not_run_an_export_that_is_not_pending(ExportStatus $status): void
    {
        Event::fake([ExportFinished::class]);
        $export = $this->makeExport($this->user, ['status' => $status]);

        $this->runJob($export);

        $this->assertSame($status, DataExport::findOrFail($export->id)->status);
        $this->assertNull(DataExport::findOrFail($export->id)->path);
        Event::assertNotDispatched(ExportFinished::class);
    }

    /** @return array<string, array{ExportStatus}> */
    public static function terminalOrBusy(): array
    {
        return [
            'processing' => [ExportStatus::PROCESSING],
            'completed' => [ExportStatus::COMPLETED],
            'failed' => [ExportStatus::FAILED],
        ];
    }

    // ---- failure paths -----------------------------------------------------------

    public function test_client_facing_failure_is_recorded_with_its_code_and_not_reported(): void
    {
        Exceptions::fake();
        $export = $this->makeExport($this->user, ['options' => ['columns' => ['removed_column']]]);

        $this->runJob($export);

        $fresh = DataExport::findOrFail($export->id);
        $this->assertSame(ExportStatus::FAILED, $fresh->status);
        $this->assertSame('unknown_column', $fresh->error_code);
        $this->assertNotNull($fresh->finished_at);
        Exceptions::assertNothingReported();
    }

    public function test_forbidden_owner_fails_the_export_with_forbidden_and_leaves_no_file(): void
    {
        Gate::define('data-export', fn (): bool => false);
        Order::create(['number' => 'A', 'total' => 1]);
        $export = $this->makeExport($this->user);

        $this->runJob($export);

        $fresh = DataExport::findOrFail($export->id);
        $this->assertSame('forbidden', $fresh->error_code);
        Storage::disk('local')->assertMissing($fresh->path);
    }

    public function test_missing_owner_fails_with_owner_missing_and_is_reported_to_operators(): void
    {
        Exceptions::fake();
        $export = $this->makeExport($this->user);
        User::query()->delete();

        $this->runJob($export);

        $this->assertSame('owner_missing', DataExport::findOrFail($export->id)->error_code);
        Exceptions::assertReported(fn (ExportException $e): bool => $e->errorCode === 'owner_missing');
    }

    public function test_unexpected_exception_becomes_export_failed_and_is_reported(): void
    {
        Exceptions::fake();
        $export = $this->makeExport($this->user, ['options' => ['columns' => ['number']]]);
        app()->bind(OrderExportable::class, fn () => throw new RuntimeException('kaboom'));

        $this->runJob($export);

        $fresh = DataExport::findOrFail($export->id);
        $this->assertSame(ExportStatus::FAILED, $fresh->status);
        $this->assertSame('export_failed', $fresh->error_code);
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'kaboom');
    }

    public function test_row_limit_growth_after_submit_fails_and_removes_the_partial_file(): void
    {
        config(['export.max_rows.csv' => 1]);
        Order::create(['number' => 'A', 'total' => 1]);
        Order::create(['number' => 'B', 'total' => 1]);
        $export = $this->makeExport($this->user);

        $this->runJob($export);

        $fresh = DataExport::findOrFail($export->id);
        $this->assertSame('row_limit_exceeded', $fresh->error_code);
        Storage::disk('local')->assertMissing($fresh->path);
        $this->assertNull($fresh->expires_at);
    }

    public function test_failure_announces_exactly_once_with_the_failed_status(): void
    {
        Event::fake([ExportFinished::class]);
        $export = $this->makeExport($this->user, ['options' => ['columns' => ['removed_column']]]);

        $this->runJob($export);

        Event::assertDispatchedTimes(ExportFinished::class, 1);
        Event::assertDispatched(ExportFinished::class, fn (ExportFinished $e): bool => $e->export->status === ExportStatus::FAILED && $e->export->error_code === 'unknown_column');
    }

    public function test_a_throwing_listener_on_the_failure_path_keeps_the_failed_status_and_is_reported(): void
    {
        Exceptions::fake();
        Event::listen(ExportFinished::class, fn () => throw new RuntimeException('mail down'));
        $export = $this->makeExport($this->user, ['options' => ['columns' => ['removed_column']]]);

        $this->runJob($export);

        $this->assertSame(ExportStatus::FAILED, DataExport::findOrFail($export->id)->status);
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'mail down');
    }

    // ---- failed() hook -----------------------------------------------------------

    #[DataProvider('inFlight')]
    public function test_failed_hook_marks_an_in_flight_export_failed_and_announces(ExportStatus $status): void
    {
        Event::fake([ExportFinished::class]);
        Exceptions::fake();
        $export = $this->makeExport($this->user, ['status' => $status]);

        (new RunExport($export->id))->failed(new RuntimeException('killed'));

        $fresh = DataExport::findOrFail($export->id);
        $this->assertSame(ExportStatus::FAILED, $fresh->status);
        $this->assertSame('export_failed', $fresh->error_code);
        Event::assertDispatchedTimes(ExportFinished::class, 1);
        Exceptions::assertReported(RuntimeException::class);
    }

    /** @return array<string, array{ExportStatus}> */
    public static function inFlight(): array
    {
        return ['pending' => [ExportStatus::PENDING], 'processing' => [ExportStatus::PROCESSING]];
    }

    public function test_failed_hook_keeps_the_error_code_of_an_export_exception(): void
    {
        $export = $this->makeExport($this->user, ['status' => ExportStatus::PROCESSING]);

        (new RunExport($export->id))->failed(ExportException::rowLimitExceeded(5));

        $this->assertSame('row_limit_exceeded', DataExport::findOrFail($export->id)->error_code);
    }

    #[DataProvider('finished')]
    public function test_failed_hook_never_overwrites_a_finished_export(ExportStatus $status): void
    {
        Event::fake([ExportFinished::class]);
        $export = $this->makeExport($this->user, ['status' => $status, 'error_code' => $status === ExportStatus::FAILED ? 'original' : null]);

        (new RunExport($export->id))->failed(new RuntimeException('late'));

        $fresh = DataExport::findOrFail($export->id);
        $this->assertSame($status, $fresh->status);
        $this->assertSame($status === ExportStatus::FAILED ? 'original' : null, $fresh->error_code);
        Event::assertNotDispatched(ExportFinished::class);
    }

    /** @return array<string, array{ExportStatus}> */
    public static function finished(): array
    {
        return ['completed' => [ExportStatus::COMPLETED], 'failed' => [ExportStatus::FAILED]];
    }

    public function test_failed_hook_with_a_deleted_row_does_nothing(): void
    {
        Event::fake([ExportFinished::class]);

        (new RunExport('00000000-0000-4000-8000-000000000000'))->failed(new RuntimeException('x'));

        Event::assertNotDispatched(ExportFinished::class);
    }
}
