<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Feature;

use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\Entities\DataExport;
use Alif\Export\Enums\ExportFormat;
use Alif\Export\Enums\ExportStatus;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportRegistry;
use Alif\Export\Jobs\RunExport;
use Alif\Export\Services\Actions\Export\ClaimExport;
use Alif\Export\Services\Actions\Export\CompleteExport;
use Alif\Export\Services\Actions\Export\DeleteExport;
use Alif\Export\Services\Actions\Export\FailExport;
use Alif\Export\Services\Actions\Export\GenerateExport;
use Alif\Export\Services\Actions\Export\RetryExport;
use Alif\Export\Services\Actions\Export\StartExport;
use Alif\Export\Tests\Concerns\MakesExports;
use Alif\Export\Tests\Fixtures\Order;
use Alif\Export\Tests\Fixtures\OrderExportable;
use Alif\Export\Tests\Fixtures\OrderLine;
use Alif\Export\Tests\Fixtures\PlainOrderExportable;
use Alif\Export\Tests\Fixtures\TranslatedOrderExportable;
use Alif\Export\Tests\Fixtures\User;
use Alif\Export\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

final class ExportActionsTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function dto(array $columns = ['number'], bool $children = false, array $child = [], ?string $title = null, string $format = 'csv'): ExportCreateDTO
    {
        return new ExportCreateDTO('orders', ExportFormat::from($format), $columns, $children, $child, $title, []);
    }

    // ---- ClaimExport -------------------------------------------------------

    public function test_claim_moves_pending_to_processing_and_records_disk_path_and_start_time(): void
    {
        $export = $this->makeExport($this->user);

        $this->assertTrue(app(ClaimExport::class)($export, 'local', 'exports/a.csv'));

        $fresh = DataExport::findOrFail($export->id);
        $this->assertSame(ExportStatus::PROCESSING, $fresh->status);
        $this->assertSame('local', $fresh->disk);
        $this->assertSame('exports/a.csv', $fresh->path);
        $this->assertNotNull($fresh->started_at);
        $this->assertSame(ExportStatus::PROCESSING, $export->status, 'the passed model is refreshed');
    }

    public function test_claim_is_idempotent_a_second_delivery_loses_and_keeps_the_first_path(): void
    {
        $export = $this->makeExport($this->user);
        $claim = app(ClaimExport::class);

        $this->assertTrue($claim($export, 'local', 'first.csv'));
        $this->assertFalse($claim($export, 'local', 'second.csv'));

        $this->assertSame('first.csv', DataExport::findOrFail($export->id)->path);
    }

    public function test_claim_uses_the_database_state_not_the_stale_model(): void
    {
        $stale = $this->makeExport($this->user);
        DataExport::whereKey($stale->id)->update(['status' => 'processing']);

        $this->assertSame(ExportStatus::PENDING, $stale->status);
        $this->assertFalse(app(ClaimExport::class)($stale, 'local', 'x.csv'));
        $this->assertNull(DataExport::findOrFail($stale->id)->path);
    }

    #[DataProvider('nonPendingStates')]
    public function test_claim_refuses_every_non_pending_state(ExportStatus $status): void
    {
        $export = $this->makeExport($this->user, ['status' => $status]);

        $this->assertFalse(app(ClaimExport::class)($export, 'local', 'x.csv'));
        $this->assertSame($status, DataExport::findOrFail($export->id)->status);
    }

    /** @return array<string, array{ExportStatus}> */
    public static function nonPendingStates(): array
    {
        return [
            'processing' => [ExportStatus::PROCESSING],
            'completed' => [ExportStatus::COMPLETED],
            'failed' => [ExportStatus::FAILED],
        ];
    }

    public function test_claim_of_a_deleted_row_is_false(): void
    {
        $export = $this->makeExport($this->user);
        DataExport::whereKey($export->id)->delete();

        $this->assertFalse(app(ClaimExport::class)($export, 'local', 'x.csv'));
    }

    // ---- CompleteExport ----------------------------------------------------

    public function test_complete_sets_status_rows_name_and_expiry(): void
    {
        Carbon::setTestNow('2026-03-04 05:06:07');
        config(['export.ttl_hours' => 6]);
        $export = $this->makeExport($this->user, ['status' => ExportStatus::PROCESSING, 'options' => ['title' => 'Sales Q1']]);

        app(CompleteExport::class)($export, 42);

        $fresh = DataExport::findOrFail($export->id);
        $this->assertSame(ExportStatus::COMPLETED, $fresh->status);
        $this->assertSame(42, $fresh->rows_count);
        $this->assertSame('sales_q1_2026-03-04_050607.csv', $fresh->file_name);
        $this->assertEquals(now(), $fresh->finished_at);
        $this->assertEquals(now()->addHours(6), $fresh->expires_at);
        $this->assertNull($fresh->error_code);
    }

    public function test_complete_names_xlsx_files_with_the_xlsx_extension_and_falls_back_to_the_exportable_key(): void
    {
        $export = $this->makeExport($this->user, ['format' => 'xlsx', 'options' => ['format' => 'xlsx']]);

        app(CompleteExport::class)($export, 0);

        $this->assertMatchesRegularExpression('/^orders_\d{4}-\d{2}-\d{2}_\d{6}\.xlsx$/', $export->file_name);
        $this->assertSame(0, $export->rows_count);
    }

    public function test_complete_slugifies_unsafe_titles_and_never_yields_an_empty_stem(): void
    {
        $unsafe = $this->makeExport($this->user, ['options' => ['title' => '../../etc/passwd "x"']]);
        app(CompleteExport::class)($unsafe, 1);
        $this->assertDoesNotMatchRegularExpression('#[/\\\\"]#', $unsafe->file_name);

        $symbols = $this->makeExport($this->user, ['options' => ['title' => '!!!']]);
        app(CompleteExport::class)($symbols, 1);
        $this->assertStringStartsWith('export_', $symbols->file_name);
    }

    // ---- FailExport --------------------------------------------------------

    public function test_fail_records_code_and_finish_time(): void
    {
        $export = $this->makeExport($this->user, ['status' => ExportStatus::PROCESSING]);

        app(FailExport::class)($export, 'row_limit_exceeded');

        $fresh = DataExport::findOrFail($export->id);
        $this->assertSame(ExportStatus::FAILED, $fresh->status);
        $this->assertSame('row_limit_exceeded', $fresh->error_code);
        $this->assertNotNull($fresh->finished_at);
        $this->assertNull($fresh->expires_at, 'a failed export has no download to expire');
    }

    // ---- DeleteExport ------------------------------------------------------

    public function test_delete_removes_row_and_file_of_a_completed_export(): void
    {
        Storage::disk('local')->put('exports/a.csv', 'x');
        $export = $this->makeExport($this->user, ['status' => ExportStatus::COMPLETED, 'disk' => 'local', 'path' => 'exports/a.csv']);

        app(DeleteExport::class)($export);

        $this->assertNull(DataExport::find($export->id));
        Storage::disk('local')->assertMissing('exports/a.csv');
    }

    public function test_delete_of_a_failed_or_pending_export_without_a_file_does_not_touch_storage(): void
    {
        Storage::disk('local')->put('keep.csv', 'x');
        foreach ([ExportStatus::FAILED, ExportStatus::PENDING] as $status) {
            $export = $this->makeExport($this->user, ['status' => $status]);
            app(DeleteExport::class)($export);
            $this->assertNull(DataExport::find($export->id));
        }

        Storage::disk('local')->assertExists('keep.csv');
    }

    public function test_delete_refuses_a_live_processing_export_and_keeps_row_and_file(): void
    {
        Storage::disk('local')->put('exports/live.csv', 'x');
        $export = $this->makeExport($this->user, ['status' => ExportStatus::PROCESSING, 'started_at' => now(), 'disk' => 'local', 'path' => 'exports/live.csv']);

        try {
            app(DeleteExport::class)($export);
            $this->fail('Expected ExportException');
        } catch (ExportException $e) {
            $this->assertSame('being_processed', $e->errorCode);
            $this->assertSame(409, $e->httpStatus());
        }

        $this->assertNotNull(DataExport::find($export->id));
        Storage::disk('local')->assertExists('exports/live.csv');
    }

    public function test_delete_race_a_stale_completed_model_cannot_delete_a_row_that_is_processing_now(): void
    {
        $stale = $this->makeExport($this->user, ['status' => ExportStatus::COMPLETED]);
        DataExport::whereKey($stale->id)->update(['status' => 'processing', 'started_at' => now()]);

        $this->expectException(ExportException::class);
        try {
            app(DeleteExport::class)($stale);
        } finally {
            $this->assertNotNull(DataExport::find($stale->id));
        }
    }

    public function test_delete_of_an_already_deleted_row_reports_being_processed_not_success(): void
    {
        $export = $this->makeExport($this->user);
        DataExport::whereKey($export->id)->delete();

        $this->expectException(ExportException::class);
        app(DeleteExport::class)($export);
    }

    public function test_delete_removes_the_file_of_a_stale_processing_export(): void
    {
        Storage::disk('local')->put('exports/stale.csv', 'x');
        $export = $this->makeExport($this->user, ['status' => ExportStatus::PROCESSING, 'started_at' => now()->subDays(2), 'disk' => 'local', 'path' => 'exports/stale.csv']);

        app(DeleteExport::class)($export);

        Storage::disk('local')->assertMissing('exports/stale.csv');
    }

    // ---- RetryExport -------------------------------------------------------

    public function test_retry_starts_a_new_pending_export_with_the_same_options_and_leaves_the_failed_one(): void
    {
        Queue::fake();
        $failed = $this->makeExport($this->user, ['status' => ExportStatus::FAILED, 'error_code' => 'export_failed', 'options' => ['columns' => ['number', 'total'], 'title' => 'T']]);

        $new = app(RetryExport::class)($this->user, $failed);

        $this->assertNotSame($failed->id, $new->id);
        $this->assertSame(ExportStatus::PENDING, $new->status);
        $this->assertEquals($failed->options, $new->options);
        $this->assertSame(ExportStatus::FAILED, DataExport::findOrFail($failed->id)->status);
        Queue::assertPushed(RunExport::class, fn (RunExport $j): bool => $j->exportId === $new->id);
    }

    #[DataProvider('nonFailedStates')]
    public function test_retry_rejects_every_state_but_failed(ExportStatus $status): void
    {
        Queue::fake();
        $export = $this->makeExport($this->user, ['status' => $status]);

        try {
            app(RetryExport::class)($this->user, $export);
            $this->fail('Expected ExportException');
        } catch (ExportException $e) {
            $this->assertSame('not_failed', $e->errorCode);
            $this->assertSame(409, $e->httpStatus());
        }
        Queue::assertNothingPushed();
        $this->assertSame(1, DataExport::count());
    }

    /** @return array<string, array{ExportStatus}> */
    public static function nonFailedStates(): array
    {
        return [
            'pending' => [ExportStatus::PENDING],
            'processing' => [ExportStatus::PROCESSING],
            'completed' => [ExportStatus::COMPLETED],
        ];
    }

    public function test_retry_is_forbidden_when_the_owner_lost_the_permission_before_any_definition_check(): void
    {
        Queue::fake();
        Gate::define('data-export', fn (): bool => false);
        $failed = $this->makeExport($this->user, ['status' => ExportStatus::FAILED, 'options' => ['exportable' => 'gone']]);

        try {
            app(RetryExport::class)($this->user, $failed);
            $this->fail('Expected ExportException');
        } catch (ExportException $e) {
            $this->assertSame('forbidden', $e->errorCode);
        }
    }

    public function test_retry_is_422_when_the_exportable_is_no_longer_registered(): void
    {
        $failed = $this->makeExport($this->user, ['status' => ExportStatus::FAILED, 'options' => ['exportable' => 'removed']]);

        $this->expectException(ValidationException::class);
        app(RetryExport::class)($this->user, $failed);
    }

    public function test_retry_is_422_when_a_child_column_disappeared(): void
    {
        $failed = $this->makeExport($this->user, ['status' => ExportStatus::FAILED, 'options' => ['include_children' => true, 'child_columns' => ['sku', 'removed']]]);

        $this->expectException(ValidationException::class);
        app(RetryExport::class)($this->user, $failed);
    }

    public function test_retry_is_422_when_children_were_requested_but_the_document_lost_its_relation(): void
    {
        app(ExportRegistry::class)->register('plain', PlainOrderExportable::class);
        $failed = $this->makeExport($this->user, ['status' => ExportStatus::FAILED, 'options' => ['exportable' => 'plain', 'include_children' => true, 'child_columns' => []]]);

        $this->expectException(ValidationException::class);
        app(RetryExport::class)($this->user, $failed);
    }

    public function test_retry_respects_the_active_quota(): void
    {
        Queue::fake();
        config(['export.max_active_per_user' => 1]);
        $this->makeExport($this->user, ['status' => ExportStatus::PENDING]);
        $failed = $this->makeExport($this->user, ['status' => ExportStatus::FAILED]);

        $this->expectException(ExportException::class);
        app(RetryExport::class)($this->user, $failed);
    }

    // ---- StartExport -------------------------------------------------------

    public function test_start_creates_a_pending_row_with_options_locale_and_dispatches_the_job(): void
    {
        Queue::fake();
        app()->setLocale('uz');

        $export = app(StartExport::class)($this->user, $this->dto(['number', 'total'], title: 'My'));

        $this->assertSame(ExportStatus::PENDING, $export->status);
        $this->assertSame((string) $this->user->getKey(), (string) $export->owner_id);
        $this->assertSame('uz', $export->locale);
        $this->assertSame('csv', $export->format);
        $this->assertSame('orders', $export->exportable);
        $this->assertSame($this->dto(['number', 'total'], title: 'My')->toArray(), $export->options);
        Queue::assertPushed(RunExport::class, 1);
    }

    public function test_start_quota_counts_only_in_flight_rows_of_the_same_owner(): void
    {
        Queue::fake();
        config(['export.max_active_per_user' => 2]);
        $other = User::create(['name' => 'o']);
        $this->makeExport($other);
        $this->makeExport($other);
        $this->makeExport($this->user, ['status' => ExportStatus::COMPLETED]);
        $this->makeExport($this->user, ['status' => ExportStatus::FAILED]);
        $this->makeExport($this->user, ['status' => ExportStatus::PENDING]);

        app(StartExport::class)($this->user, $this->dto());

        try {
            app(StartExport::class)($this->user, $this->dto());
            $this->fail('Expected ExportException');
        } catch (ExportException $e) {
            $this->assertSame('too_many_active', $e->errorCode);
            $this->assertSame(429, $e->httpStatus());
        }
    }

    public function test_start_quota_ignores_stale_rows(): void
    {
        Queue::fake();
        config(['export.max_active_per_user' => 1]);
        $this->makeExport($this->user, ['status' => ExportStatus::PROCESSING, 'started_at' => now()->subDays(3)]);

        $this->assertSame(ExportStatus::PENDING, app(StartExport::class)($this->user, $this->dto())->status);
    }

    public function test_start_with_a_zero_quota_always_refuses(): void
    {
        Queue::fake();
        config(['export.max_active_per_user' => 0]);

        $this->expectException(ExportException::class);
        app(StartExport::class)($this->user, $this->dto());
    }

    public function test_start_over_the_row_cap_is_422_on_file_format_and_creates_nothing(): void
    {
        Queue::fake();
        config(['export.max_rows.csv' => 2]);
        foreach (['a', 'b', 'c'] as $n) {
            Order::create(['number' => $n, 'total' => 1]);
        }

        try {
            app(StartExport::class)($this->user, $this->dto());
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('file.format', $e->errors());
        }
        $this->assertSame(0, DataExport::count());
        Queue::assertNothingPushed();
    }

    public function test_start_exactly_at_the_row_cap_is_accepted(): void
    {
        Queue::fake();
        config(['export.max_rows.csv' => 2]);
        Order::create(['number' => 'a', 'total' => 1]);
        Order::create(['number' => 'b', 'total' => 1]);

        $this->assertSame(ExportStatus::PENDING, app(StartExport::class)($this->user, $this->dto())->status);
    }

    public function test_start_cap_counts_flattened_children_per_format(): void
    {
        Queue::fake();
        config(['export.max_rows.csv' => 3, 'export.max_rows.xlsx' => 10]);
        $o = Order::create(['number' => 'a', 'total' => 1]);
        foreach (['s1', 's2', 's3', 's4'] as $sku) {
            OrderLine::create(['order_id' => $o->id, 'sku' => $sku]);
        }

        app(StartExport::class)($this->user, $this->dto(['number'], true, ['sku'], format: 'xlsx'));

        $this->expectException(ValidationException::class);
        app(StartExport::class)($this->user, $this->dto(['number'], true, ['sku'], format: 'csv'));
    }

    public function test_start_unknown_exportable_throws_unknown_exportable(): void
    {
        try {
            app(StartExport::class)($this->user, new ExportCreateDTO('nope', ExportFormat::CSV, ['number'], false, [], null, []));
            $this->fail('Expected ExportException');
        } catch (ExportException $e) {
            $this->assertSame('unknown_exportable', $e->errorCode);
        }
    }

    public function test_start_marks_the_row_failed_and_rethrows_when_dispatch_fails(): void
    {
        config([
            'queue.default' => 'broken',
            'queue.connections.broken' => ['driver' => 'database', 'table' => 'no_such_jobs_table', 'queue' => 'default', 'retry_after' => 90],
        ]);

        try {
            app(StartExport::class)($this->user, $this->dto());
            $this->fail('Expected the dispatch error to propagate');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(ExportException::class, $e);
        }

        $row = DataExport::query()->firstOrFail();
        $this->assertSame(ExportStatus::FAILED, $row->status);
        $this->assertSame('export_dispatch_failed', $row->error_code);
    }

    public function test_start_releases_nothing_for_a_failed_dispatch_so_the_quota_is_free_again(): void
    {
        config([
            'queue.default' => 'broken',
            'queue.connections.broken' => ['driver' => 'database', 'table' => 'no_such_jobs_table', 'queue' => 'default', 'retry_after' => 90],
            'export.max_active_per_user' => 1,
        ]);
        try {
            app(StartExport::class)($this->user, $this->dto());
        } catch (\Throwable) {
        }

        Queue::fake();
        config(['queue.default' => 'sync']);

        $this->assertSame(ExportStatus::PENDING, app(StartExport::class)($this->user, $this->dto())->status);
    }

    // ---- GenerateExport ----------------------------------------------------

    private function claimed(array $options = []): DataExport
    {
        $export = $this->makeExport($this->user, ['options' => $options]);
        app(ClaimExport::class)($export, 'local', 'exports/g.csv');

        return $export;
    }

    public function test_generate_writes_the_file_and_returns_the_row_count(): void
    {
        Order::create(['number' => 'A', 'total' => 1]);
        Order::create(['number' => 'B', 'total' => 2]);

        $rows = app(GenerateExport::class)($this->claimed(['columns' => ['number', 'total']]));

        $this->assertSame(2, $rows);
        $csv = Storage::disk('local')->get('exports/g.csv');
        $this->assertStringContainsString('Number,Total', $csv);
        $this->assertMatchesRegularExpression('/^A,1(\.0+)?$/m', $csv);
    }

    public function test_generate_with_no_matching_documents_writes_a_header_only_file(): void
    {
        $this->assertSame(0, app(GenerateExport::class)($this->claimed()));
        $this->assertSame("\xEF\xBB\xBFNumber\n", Storage::disk('local')->get('exports/g.csv'));
    }

    public function test_generate_is_forbidden_and_writes_nothing_when_the_owner_lost_the_permission(): void
    {
        Order::create(['number' => 'A', 'total' => 1]);
        Gate::define('data-export', fn (): bool => false);

        try {
            app(GenerateExport::class)($this->claimed());
            $this->fail('Expected ExportException');
        } catch (ExportException $e) {
            $this->assertSame('forbidden', $e->errorCode);
        }
        Storage::disk('local')->assertMissing('exports/g.csv');
    }

    public function test_generate_fails_with_owner_missing_when_the_owner_row_is_gone(): void
    {
        $export = $this->claimed();
        User::query()->whereKey($this->user->getKey())->delete();

        try {
            app(GenerateExport::class)($export);
            $this->fail('Expected ExportException');
        } catch (ExportException $e) {
            $this->assertSame('owner_missing', $e->errorCode);
        }
    }

    public function test_generate_runs_in_the_export_locale_and_restores_the_previous_one(): void
    {
        app('translator')->addLines(['messages.col_number' => 'Raqam'], 'uz');
        app(ExportRegistry::class)->register('translated', TranslatedOrderExportable::class);
        $export = $this->makeExport($this->user, ['locale' => 'uz', 'options' => ['exportable' => 'translated', 'columns' => ['number']]]);
        app(ClaimExport::class)($export, 'local', 'exports/g.csv');
        app()->setLocale('en');

        app(GenerateExport::class)($export);

        $this->assertStringContainsString('Raqam', Storage::disk('local')->get('exports/g.csv'));
        $this->assertSame('en', app()->getLocale());
    }

    public function test_generate_restores_locale_and_lazy_loading_guard_even_when_it_throws(): void
    {
        $export = $this->claimed(['columns' => ['undeclared']]);
        Order::create(['number' => 'A', 'total' => 1]);
        OrderLine::create(['order_id' => Order::first()->id, 'sku' => 's']);
        $export->update(['locale' => 'fr']);
        app()->setLocale('en');
        Model::preventLazyLoading(false);

        try {
            app(GenerateExport::class)($export);
            $this->fail('Expected lazy loading violation');
        } catch (\Throwable) {
        }

        $this->assertSame('en', app()->getLocale());
        $this->assertFalse(Model::preventsLazyLoading());
    }

    public function test_generate_uses_the_title_option_as_sheet_title_without_crashing_for_xlsx(): void
    {
        Order::create(['number' => 'A', 'total' => 1]);
        $export = $this->makeExport($this->user, ['format' => 'xlsx', 'options' => ['format' => 'xlsx', 'title' => 'Custom']]);
        app(ClaimExport::class)($export, 'local', 'exports/g.xlsx');

        $this->assertSame(1, app(GenerateExport::class)($export));
        Storage::disk('local')->assertExists('exports/g.xlsx');
    }
}
