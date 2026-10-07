<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Feature;

use Alif\Export\Entities\DataExport;
use Alif\Export\Enums\ExportStatus;
use Alif\Export\Events\ExportFinished;
use Alif\Export\Helpers\ExportRegistry;
use Alif\Export\Jobs\RunExport;
use Alif\Export\Tests\Fixtures\Order;
use Alif\Export\Tests\Fixtures\OrderExportable;
use Alif\Export\Tests\Fixtures\OrderLine;
use Alif\Export\Tests\Fixtures\PlainOrderExportable;
use Alif\Export\Tests\Fixtures\User;
use Alif\Export\Tests\TestCase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Http\FormRequest as BaseFormRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class ExportManagementTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        app(ExportRegistry::class)->register('orders', OrderExportable::class);
        Gate::define('data-export', fn ($user, string $key): bool => true);

        $this->user = User::create(['name' => 'u']);
    }

    /** @return array<string, mixed> */
    private function payload(array $file = []): array
    {
        return ['exportable' => 'orders', 'file' => $file + ['format' => 'csv', 'columns' => ['number', 'total']]];
    }

    /** @param array<string, mixed> $attrs */
    private function makeExport(array $attrs = [], ?User $owner = null): DataExport
    {
        return DataExport::query()->create($attrs + [
            'owner_id' => (string) ($owner ?? $this->user)->getKey(),
            'exportable' => 'orders',
            'format' => 'csv',
            'status' => ExportStatus::PENDING,
            'options' => ['exportable' => 'orders', 'format' => 'csv', 'columns' => ['number'], 'include_children' => false, 'child_columns' => [], 'title' => null, 'parameters' => []],
            'locale' => 'en',
        ])->refresh();
    }

    // ---- list ---------------------------------------------------------------

    public function test_index_lists_only_own_exports_newest_first_with_filters(): void
    {
        $other = User::create(['name' => 'o']);
        $old = $this->makeExport(['status' => ExportStatus::FAILED, 'created_at' => now()->subHour()]);
        $new = $this->makeExport(['status' => ExportStatus::PENDING]);
        $this->makeExport([], $other);

        $this->actingAs($this->user)->getJson('/exports')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $new->id)
            ->assertJsonPath('data.1.id', $old->id);

        $this->actingAs($this->user)->getJson('/exports?status=failed')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old->id);
    }

    public function test_index_validates_filters_and_paginates(): void
    {
        $this->actingAs($this->user)->getJson('/exports?status=bogus')->assertStatus(422);
        $this->actingAs($this->user)->getJson('/exports?per_page=101')->assertStatus(422);

        foreach (range(1, 3) as $_) {
            $this->makeExport(['status' => ExportStatus::FAILED]);
        }
        $this->actingAs($this->user)->getJson('/exports?per_page=2')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.per_page', 2);
    }

    // ---- delete / cancel ------------------------------------------------------

    public function test_pending_export_can_be_cancelled_and_the_job_then_does_nothing(): void
    {
        Queue::fake();
        $id = $this->actingAs($this->user)->postJson('/exports', $this->payload())->assertStatus(202)->json('data.id');
        Queue::assertPushed(RunExport::class);

        $this->actingAs($this->user)->deleteJson("/exports/{$id}")->assertNoContent();
        $this->assertNull(DataExport::find($id));

        app()->call([new RunExport($id), 'handle']);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_completed_export_delete_removes_the_file(): void
    {
        Order::create(['number' => 'A', 'total' => '1']);
        $id = $this->actingAs($this->user)->postJson('/exports', $this->payload())->json('data.id');
        $export = DataExport::findOrFail($id);
        $this->assertTrue(Storage::disk('local')->exists($export->path));

        $this->actingAs($this->user)->deleteJson("/exports/{$id}")->assertNoContent();

        $this->assertFalse(Storage::disk('local')->exists($export->path));
        $this->assertNull(DataExport::find($id));
    }

    public function test_live_processing_export_cannot_be_deleted_but_a_stale_one_can(): void
    {
        $live = $this->makeExport(['status' => ExportStatus::PROCESSING, 'started_at' => now()]);
        $this->actingAs($this->user)->deleteJson("/exports/{$live->id}")->assertStatus(409);
        $this->assertNotNull(DataExport::find($live->id));

        $stale = $this->makeExport(['status' => ExportStatus::PROCESSING, 'started_at' => now()->subDay()]);
        $this->actingAs($this->user)->deleteJson("/exports/{$stale->id}")->assertNoContent();
    }

    public function test_foreign_export_is_404_for_delete_and_retry(): void
    {
        $other = User::create(['name' => 'o']);
        $theirs = $this->makeExport(['status' => ExportStatus::FAILED], $other);

        $this->actingAs($this->user)->deleteJson("/exports/{$theirs->id}")->assertNotFound();
        $this->actingAs($this->user)->postJson("/exports/{$theirs->id}/retry")->assertNotFound();
        $this->assertNotNull(DataExport::find($theirs->id));
    }

    // ---- retry ---------------------------------------------------------------

    public function test_failed_export_can_be_retried_as_a_new_export(): void
    {
        Order::create(['number' => 'A', 'total' => '1']);
        $failed = $this->makeExport(['status' => ExportStatus::FAILED, 'error_code' => 'export_failed']);

        $response = $this->actingAs($this->user)->postJson("/exports/{$failed->id}/retry")->assertStatus(202);

        $this->assertNotSame($failed->id, $response->json('data.id'));
        $this->assertSame(ExportStatus::COMPLETED, DataExport::findOrFail($response->json('data.id'))->status);
        $this->assertSame(ExportStatus::FAILED, $failed->refresh()->status);
    }

    public function test_only_failed_exports_can_be_retried(): void
    {
        foreach ([ExportStatus::PENDING, ExportStatus::PROCESSING, ExportStatus::COMPLETED] as $status) {
            $export = $this->makeExport(['status' => $status]);
            $this->actingAs($this->user)->postJson("/exports/{$export->id}/retry")->assertStatus(409);
        }
    }

    public function test_retry_is_422_when_definition_no_longer_has_the_columns_and_403_when_denied(): void
    {
        $gone = $this->makeExport([
            'status' => ExportStatus::FAILED,
            'options' => ['exportable' => 'orders', 'format' => 'csv', 'columns' => ['removed'], 'include_children' => false, 'child_columns' => [], 'title' => null, 'parameters' => []],
        ]);
        $this->actingAs($this->user)->postJson("/exports/{$gone->id}/retry")
            ->assertStatus(422)->assertJsonValidationErrors(['exportable']);

        $failed = $this->makeExport(['status' => ExportStatus::FAILED]);
        Gate::define('data-export', fn (): bool => false);
        $this->actingAs($this->user)->postJson("/exports/{$failed->id}/retry")->assertForbidden();
    }

    public function test_retry_respects_the_active_quota(): void
    {
        config(['queue.default' => 'null', 'export.max_active_per_user' => 1]);
        $this->makeExport(['status' => ExportStatus::PENDING]);
        $failed = $this->makeExport(['status' => ExportStatus::FAILED]);

        $this->actingAs($this->user)->postJson("/exports/{$failed->id}/retry")->assertStatus(429);
    }

    // ---- download re-authorisation ------------------------------------------------

    public function test_download_is_403_once_the_owner_loses_the_permission(): void
    {
        Order::create(['number' => 'A', 'total' => '1']);
        $id = $this->actingAs($this->user)->postJson('/exports', $this->payload())->json('data.id');
        $this->actingAs($this->user)->get("/exports/{$id}/download")->assertOk();

        Gate::define('data-export', fn (): bool => false);

        $this->actingAs($this->user)->get("/exports/{$id}/download")->assertForbidden();
    }

    // ---- event ---------------------------------------------------------------------

    public function test_finished_event_fires_for_completed_and_failed_exports(): void
    {
        Event::fake([ExportFinished::class]);
        Order::create(['number' => 'A', 'total' => '1']);

        $ok = $this->actingAs($this->user)->postJson('/exports', $this->payload())->json('data.id');
        $bad = $this->actingAs($this->user)->postJson('/exports', $this->payload(['columns' => ['undeclared'], 'include_children' => false]))->json('data.id');

        Event::assertDispatched(ExportFinished::class, fn (ExportFinished $e): bool => $e->export->id === $ok && $e->export->status === ExportStatus::COMPLETED);
        Event::assertDispatched(ExportFinished::class, fn (ExportFinished $e): bool => $e->export->id === $bad && $e->export->status === ExportStatus::FAILED);
    }

    // ---- host with failOnUnknownFields ------------------------------------------------

    public function test_nested_filter_data_is_accepted_when_the_host_fails_on_unknown_fields(): void
    {
        FormRequest::failOnUnknownFields();
        $this->beforeApplicationDestroyed(fn () => FormRequest::failOnUnknownFields(false));
        Order::create(['number' => 'A', 'total' => '1']);
        Order::create(['number' => 'B', 'total' => '9']);

        $response = $this->actingAs($this->user)->postJson('/exports', $this->payload() + [
            'data' => ['filter' => ['total' => ['gte' => 5]], 'sort' => '-number'],
        ]);

        $response->assertStatus(202);
        $this->assertSame(1, DataExport::findOrFail($response->json('data.id'))->rows_count);
    }

    // ---- regressions found in review ---------------------------------------------------

    public function test_a_throwing_finished_listener_does_not_fail_or_delete_a_completed_export(): void
    {
        Order::create(['number' => 'A', 'total' => '1']);
        Event::listen(ExportFinished::class, fn () => throw new \RuntimeException('mail down'));

        $id = $this->actingAs($this->user)->postJson('/exports', $this->payload())->assertStatus(202)->json('data.id');

        $export = DataExport::findOrFail($id);
        $this->assertSame(ExportStatus::COMPLETED, $export->status, (string) $export->error_code);
        $this->assertTrue(Storage::disk('local')->exists($export->path));
    }

    public function test_listing_does_not_touch_the_storage_disk_per_item(): void
    {
        // The file is missing on purpose: the list must not look for it (one storage call per item on S3).
        $export = $this->makeExport([
            'status' => ExportStatus::COMPLETED, 'disk' => 'local', 'path' => 'exports/missing.csv', 'expires_at' => now()->addHour(),
        ]);

        $this->actingAs($this->user)->getJson('/exports')->assertOk()->assertJsonPath('data.0.download_url', route('export.download', $export->id));
        $this->actingAs($this->user)->get("/exports/{$export->id}/download")->assertStatus(410);
    }

    public function test_quota_is_checked_before_the_filter_and_row_count_are_evaluated(): void
    {
        config(['queue.default' => 'null', 'export.max_active_per_user' => 1]);
        $this->makeExport(['status' => ExportStatus::PENDING]);

        // An invalid filter would be a 422 if the request were evaluated first.
        $this->actingAs($this->user)->postJson('/exports', $this->payload() + ['data' => ['filter' => ['total' => ['bogus' => 1]]]])
            ->assertStatus(429);
    }

    public function test_index_accepts_page_when_the_host_fails_on_unknown_fields(): void
    {
        BaseFormRequest::failOnUnknownFields();
        $this->beforeApplicationDestroyed(fn () => BaseFormRequest::failOnUnknownFields(false));

        $this->actingAs($this->user)->getJson('/exports?page=1&per_page=5')->assertOk();
    }

    public function test_definition_needs_the_exportable_query_parameter(): void
    {
        $this->actingAs($this->user)->getJson('/exports/definition')->assertForbidden();
        $this->actingAs($this->user)->getJson('/exports/definition?exportable=orders')->assertOk()->assertJsonPath('data.key', 'orders');
    }

    public function test_exportables_can_be_registered_through_config(): void
    {
        config(['export.exportables' => ['plain.orders' => PlainOrderExportable::class]]);
        app()->forgetInstance(ExportRegistry::class);

        $this->assertTrue(app(ExportRegistry::class)->has('plain.orders'));
    }

    public function test_a_column_removed_from_the_definition_after_submit_fails_the_job_with_unknown_column(): void
    {
        Queue::fake();
        $id = $this->actingAs($this->user)->postJson('/exports', $this->payload())->assertStatus(202)->json('data.id');
        DataExport::query()->whereKey($id)->update(['options' => ['exportable' => 'orders', 'format' => 'csv', 'columns' => ['gone'], 'include_children' => false, 'child_columns' => [], 'title' => null, 'parameters' => []]]);

        app()->call([new RunExport($id), 'handle']);

        $this->assertSame('unknown_column', DataExport::findOrFail($id)->error_code);
    }

    // ---- real file contents ----------------------------------------------------------

    public function test_xlsx_file_opens_and_contains_flattened_rows(): void
    {
        $a = Order::create(['number' => 'A-1', 'total' => '10.5']);
        Order::create(['number' => '=EVIL()', 'total' => '5']);
        OrderLine::create(['order_id' => $a->id, 'sku' => 's1', 'qty' => 2]);
        OrderLine::create(['order_id' => $a->id, 'sku' => 's2', 'qty' => 3]);

        $id = $this->actingAs($this->user)->postJson('/exports', $this->payload([
            'format' => 'xlsx', 'include_children' => true, 'child_columns' => ['sku', 'qty'],
        ]))->assertStatus(202)->json('data.id');

        $export = DataExport::findOrFail($id);
        $this->assertSame(ExportStatus::COMPLETED, $export->status, (string) $export->error_code);
        $this->assertSame(3, $export->rows_count);

        $local = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($local, Storage::disk('local')->get($export->path));
        $rows = IOFactory::load($local)->getActiveSheet()->toArray();
        unlink($local);

        $this->assertSame(['Number', 'Total', 'SKU', 'Qty'], $rows[0]);
        $this->assertCount(4, $rows);
        $byNumber = collect($rows)->groupBy(0);
        $this->assertCount(2, $byNumber['A-1']);
        $this->assertSame('=EVIL()', $byNumber['=EVIL()'][0][0]);
    }

    // ---- job-level cap -------------------------------------------------------------

    public function test_job_fails_with_row_limit_when_data_grew_after_submit(): void
    {
        config(['export.max_rows.csv' => 2]);
        Order::create(['number' => 'A', 'total' => '1']);
        Queue::fake();
        $id = $this->actingAs($this->user)->postJson('/exports', $this->payload())->assertStatus(202)->json('data.id');

        Order::create(['number' => 'B', 'total' => '1']);
        Order::create(['number' => 'C', 'total' => '1']);
        app()->call([new RunExport($id), 'handle']);

        $export = DataExport::findOrFail($id);
        $this->assertSame(ExportStatus::FAILED, $export->status);
        $this->assertSame('row_limit_exceeded', $export->error_code);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_redelivered_job_does_not_run_twice(): void
    {
        Order::create(['number' => 'A', 'total' => '1']);
        Queue::fake();
        $id = $this->actingAs($this->user)->postJson('/exports', $this->payload())->json('data.id');
        $handle = fn () => app()->call([new RunExport($id), 'handle']);

        $handle();
        $first = DataExport::findOrFail($id);
        $handle();
        $second = DataExport::findOrFail($id);

        $this->assertSame($first->path, $second->path);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }
}
