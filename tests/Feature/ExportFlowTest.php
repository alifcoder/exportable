<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Feature;

use Alif\Export\Entities\DataExport;
use Alif\Export\Helpers\ExportRegistry;
use Alif\Export\Jobs\RunExport;
use Alif\Export\Tests\Fixtures\Order;
use Alif\Export\Tests\Fixtures\OrderExportable;
use Alif\Export\Tests\Fixtures\OrderFilter;
use Alif\Export\Tests\Fixtures\OrderLine;
use Alif\Export\Tests\Fixtures\User;
use Alif\Export\Tests\TestCase;
use Alif\Export\Transformers\Export\ExportResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

final class ExportFlowTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        app(ExportRegistry::class)->register('orders', OrderExportable::class);
        Gate::define('data-export', fn ($user, string $key): bool => $key === 'orders');

        $this->user = User::create(['name' => 'u']);

        $a = Order::create(['number' => 'A-1', 'total' => '10.500000']);
        Order::create(['number' => '=SUM(A1)', 'total' => '5']);
        foreach (['s1', 's2', 's3'] as $sku) {
            OrderLine::create(['order_id' => $a->id, 'sku' => $sku, 'qty' => 2]);
        }
    }

    protected function tearDown(): void
    {
        OrderFilter::$scopeByAuthUser = false;

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function payload(bool $children): array
    {
        return [
            'exportable' => 'orders',
            'data' => ['sort' => 'number', 'all' => true, 'limit' => 1],
            'file' => [
                'format' => 'csv',
                'columns' => ['number', 'total'],
                'include_children' => $children,
                ...($children ? ['child_columns' => ['sku', 'qty']] : []),
            ],
        ];
    }

    public function test_csv_flow_store_job_download(): void
    {
        $response = $this->actingAs($this->user)->postJson('/exports', $this->payload(true));
        $response->assertStatus(202);

        $show = $this->actingAs($this->user)->getJson('/exports/'.$response->json('data.id'));
        $show->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.rows_count', 4);

        $download = $this->actingAs($this->user)->get($show->json('data.download_url'));
        $download->assertOk();
        $csv = $download->streamedContent();

        $this->assertStringContainsString('Number,Total,SKU,Qty', $csv);
        $this->assertStringContainsString("'=SUM(A1)", $csv);
        $this->assertSame(5, substr_count(trim($csv), "\n") + 1);
    }

    public function test_without_children_gives_one_row_per_document(): void
    {
        $id = $this->actingAs($this->user)->postJson('/exports', $this->payload(false))->json('data.id');

        $this->assertSame(2, DataExport::findOrFail($id)->rows_count);
    }

    public function test_denied_gate_returns_403_and_writes_nothing(): void
    {
        Gate::define('data-export', fn (): bool => false);

        $this->actingAs($this->user)->postJson('/exports', $this->payload(false))->assertForbidden();
        $this->assertSame(0, DataExport::count());
    }

    public function test_unknown_column_and_prohibited_param_are_rejected(): void
    {
        config()->set('export.prohibited_parameters', ['all']);
        $bad = $this->payload(false);
        $bad['file']['columns'] = ['nope'];
        $bad['all'] = true;

        $this->actingAs($this->user)->postJson('/exports', $bad)
            ->assertStatus(422)->assertJsonValidationErrors(['file.columns.0', 'all']);
    }

    public function test_non_owner_gets_404(): void
    {
        $id = $this->actingAs($this->user)->postJson('/exports', $this->payload(false))->json('data.id');
        $other = User::create(['name' => 'o']);

        $this->actingAs($other)->getJson("/exports/{$id}")->assertNotFound();
    }

    public function test_xlsx_is_written(): void
    {
        foreach (['xlsx'] as $format) {
            $payload = $this->payload(true);
            $payload['file']['format'] = $format;
            $id = $this->actingAs($this->user)->postJson('/exports', $payload)->json('data.id');
            $export = DataExport::findOrFail($id);

            $this->assertSame('completed', $export->status, $format.' '.$export->error_code);
            Storage::disk('local')->assertExists($export->path);
        }
    }

    public function test_undeclared_relation_fails_the_job_instead_of_lazy_loading(): void
    {
        $payload = $this->payload(false);
        $payload['file']['columns'] = ['number', 'undeclared'];

        $id = $this->actingAs($this->user)->postJson('/exports', $payload)->json('data.id');
        $export = DataExport::findOrFail($id);

        $this->assertSame('failed', $export->status);
        $this->assertSame('export_failed', $export->error_code);
    }

    public function test_undeclared_relation_fails_even_for_a_single_document(): void
    {
        Order::where('number', '=SUM(A1)')->delete();
        $payload = $this->payload(false);
        $payload['file']['columns'] = ['number', 'undeclared'];

        $id = $this->actingAs($this->user)->postJson('/exports', $payload)->json('data.id');

        $this->assertSame('failed', DataExport::findOrFail($id)->status);
    }

    public function test_row_cap_counts_flattened_output_rows_at_submit(): void
    {
        config(['export.max_rows.csv' => 1]);
        $this->actingAs($this->user)->postJson('/exports', $this->payload(false))
            ->assertStatus(422)->assertJsonValidationErrors(['file.format']);

        // 4 flattened output rows exceed the cap although there are fewer documents.
        config(['export.max_rows.csv' => 3]);
        $this->actingAs($this->user)->postJson('/exports', $this->payload(true))
            ->assertStatus(422)->assertJsonValidationErrors(['file.format']);
        $this->assertSame(0, DataExport::query()->count());

        config(['export.max_rows.csv' => 4]);
        $this->actingAs($this->user)->postJson('/exports', $this->payload(true))->assertStatus(202);
    }

    public function test_active_export_limit_returns_429(): void
    {
        config(['queue.default' => 'null']);
        foreach (range(1, 3) as $_) {
            $this->actingAs($this->user)->postJson('/exports', $this->payload(false))->assertStatus(202);
        }

        $this->actingAs($this->user)->postJson('/exports', $this->payload(false))->assertStatus(429);
    }

    public function test_job_runs_as_owner_so_host_scope_applies_without_a_request_user(): void
    {
        OrderFilter::$scopeByAuthUser = true;
        $other = User::create(['name' => 'o']);
        Order::create(['number' => 'MINE-1', 'total' => '1', 'owner' => $this->user->id]);
        Order::create(['number' => 'MINE-2', 'total' => '2', 'owner' => $this->user->id]);
        Order::create(['number' => 'THEIRS-1', 'total' => '3', 'owner' => $other->id]);

        Queue::fake();
        $id = $this->actingAs($this->user)->postJson('/exports', $this->payload(false))->assertStatus(202)->json('data.id');
        Queue::assertPushed(RunExport::class);
        $this->assertSame('pending', DataExport::findOrFail($id)->status);

        // Worker process: nobody is authenticated.
        Auth::forgetUser();
        $this->assertNull(auth()->user());

        app()->call([new RunExport($id), 'handle']);

        $export = DataExport::findOrFail($id);
        $this->assertSame('completed', $export->status, (string) $export->error_code);
        $csv = Storage::disk('local')->get($export->path);

        $this->assertStringContainsString('MINE-1', $csv);
        $this->assertStringContainsString('MINE-2', $csv);
        $this->assertStringNotContainsString('THEIRS-1', $csv);
        $this->assertStringNotContainsString('A-1', $csv);
        $this->assertSame(2, $export->rows_count);
        $this->assertNull(auth()->user(), 'The job must not leak the owner into the worker.');
    }

    public function test_children_are_flattened_with_repeated_document_cells_and_blank_child_cells(): void
    {
        $id = $this->actingAs($this->user)->postJson('/exports', $this->payload(true))->json('data.id');
        $csv = Storage::disk('local')->get(DataExport::findOrFail($id)->path);

        $lines = array_map(
            fn (string $line): array => str_getcsv($line, ',', '"', ''),
            array_values(array_filter(explode("\n", str_replace("\xEF\xBB\xBF", '', $csv)))),
        );

        $this->assertSame(['Number', 'Total', 'SKU', 'Qty'], $lines[0]);

        $withChildren = array_values(array_filter($lines, fn (array $l): bool => $l[0] === 'A-1'));
        $this->assertCount(3, $withChildren);
        $this->assertSame(['s1', 's2', 's3'], array_column($withChildren, 2));
        $totals = array_values(array_unique(array_column($withChildren, 1)));
        $this->assertCount(1, $totals);
        $this->assertEqualsWithDelta(10.5, (float) $totals[0], 0.0000001);

        $childless = array_values(array_filter($lines, fn (array $l): bool => $l[0] === "'=SUM(A1)"));
        $this->assertCount(1, $childless);
        $this->assertSame(['', ''], array_slice($childless[0], 2));
    }

    public function test_non_uuid_export_id_is_404(): void
    {
        $this->actingAs($this->user)->getJson('/exports/not-a-uuid')->assertNotFound();
        $this->actingAs($this->user)->getJson('/exports/not-a-uuid/download')->assertNotFound();
    }

    /** @param array<string, mixed> $attrs */
    private function makeExport(array $attrs): DataExport
    {
        $export = DataExport::query()->create($attrs + [
            'owner_id' => (string) $this->user->getKey(),
            'exportable' => 'orders',
            'format' => 'csv',
            'status' => DataExport::STATUS_PENDING,
            'options' => [],
            'locale' => 'en',
        ]);

        if (isset($attrs['created_at'])) {
            DataExport::query()->whereKey($export->id)->update(['created_at' => $attrs['created_at']]);
        }

        return $export->refresh();
    }

    public function test_live_processing_row_with_old_created_at_survives_prune(): void
    {
        $row = $this->makeExport([
            'status' => DataExport::STATUS_PROCESSING,
            'created_at' => now()->subMinutes(45),
            'started_at' => now()->subMinutes(5),
        ]);

        $this->artisan('model:prune', ['--model' => [DataExport::class]])->assertSuccessful();

        $this->assertNotNull(DataExport::query()->find($row->id));
        $this->assertSame(1, DataExport::query()->active()->count());
    }

    public function test_processing_row_with_old_started_at_is_pruned_with_its_file(): void
    {
        Storage::disk('local')->put('exports/stuck.csv', 'x');
        $row = $this->makeExport([
            'status' => DataExport::STATUS_PROCESSING,
            'created_at' => now()->subHours(3),
            'started_at' => now()->subHours(2),
            'disk' => 'local',
            'path' => 'exports/stuck.csv',
        ]);

        $this->artisan('model:prune', ['--model' => [DataExport::class]])->assertSuccessful();

        $this->assertNull(DataExport::query()->find($row->id));
        Storage::disk('local')->assertMissing('exports/stuck.csv');
    }

    public function test_long_waiting_pending_row_survives_prune_and_counts_toward_quota(): void
    {
        config(['queue.default' => 'null']);
        foreach (range(1, 3) as $_) {
            $this->makeExport(['created_at' => now()->subHours(3)]);
        }

        $this->artisan('model:prune', ['--model' => [DataExport::class]])->assertSuccessful();

        $this->assertSame(3, DataExport::query()->count());
        $this->actingAs($this->user)->postJson('/exports', $this->payload(false))->assertStatus(429);
    }

    public function test_pending_row_beyond_pending_cutoff_is_pruned_and_not_counted(): void
    {
        $row = $this->makeExport(['created_at' => now()->subHours(30)]);

        $this->assertSame(0, DataExport::query()->active()->count());
        $this->artisan('model:prune', ['--model' => [DataExport::class]])->assertSuccessful();

        $this->assertNull(DataExport::query()->find($row->id));
    }

    public function test_failed_row_older_than_ttl_is_pruned_and_recent_failed_kept(): void
    {
        $old = $this->makeExport(['status' => DataExport::STATUS_FAILED, 'finished_at' => now()->subHours(48)]);
        $recent = $this->makeExport(['status' => DataExport::STATUS_FAILED, 'finished_at' => now()->subHour()]);

        $this->artisan('model:prune', ['--model' => [DataExport::class]])->assertSuccessful();

        $this->assertNull(DataExport::query()->find($old->id));
        $this->assertNotNull(DataExport::query()->find($recent->id));
    }

    public function test_prune_query_groups_conditions_and_terminates_across_chunks(): void
    {
        $sql = (new DataExport)->prunable()->toSql();
        $this->assertMatchesRegularExpression('/^select \* from "data_exports" where \(.+\)$/', $sql);

        foreach (range(1, 5) as $_) {
            $this->makeExport(['status' => DataExport::STATUS_FAILED, 'finished_at' => now()->subHours(48)]);
        }
        $keep = $this->makeExport(['status' => DataExport::STATUS_COMPLETED, 'expires_at' => now()->addHour()]);

        $this->assertSame(5, (new DataExport)->pruneAll(2));
        $this->assertSame([$keep->id], DataExport::query()->pluck('id')->all());
    }

    public function test_processing_row_without_started_at_uses_created_at_for_staleness(): void
    {
        $stale = $this->makeExport(['status' => DataExport::STATUS_PROCESSING, 'created_at' => now()->subHours(3)]);
        $live = $this->makeExport(['status' => DataExport::STATUS_PROCESSING, 'created_at' => now()->subMinutes(5)]);

        $this->assertSame([$live->id], DataExport::query()->active()->pluck('id')->all());
        $this->assertSame([$stale->id], DataExport::query()->stale()->pluck('id')->all());

        $this->artisan('model:prune', ['--model' => [DataExport::class]])->assertSuccessful();

        $this->assertNull(DataExport::query()->find($stale->id));
        $this->assertNotNull(DataExport::query()->find($live->id));
    }

    public function test_failed_row_without_finished_at_is_pruned_by_updated_at(): void
    {
        $old = $this->makeExport(['status' => DataExport::STATUS_FAILED]);
        DataExport::query()->whereKey($old->id)->update(['updated_at' => now()->subHours(48)]);
        $recent = $this->makeExport(['status' => DataExport::STATUS_FAILED]);

        $this->artisan('model:prune', ['--model' => [DataExport::class]])->assertSuccessful();

        $this->assertNull(DataExport::query()->find($old->id));
        $this->assertNotNull(DataExport::query()->find($recent->id));
    }

    public function test_stale_processing_row_with_claimed_path_has_file_deleted_and_is_not_downloadable(): void
    {
        Storage::disk('local')->put('exports/crashed.csv', 'x');
        $row = $this->makeExport([
            'status' => DataExport::STATUS_PROCESSING,
            'started_at' => now()->subHours(2),
            'disk' => 'local',
            'path' => 'exports/crashed.csv',
        ]);

        $this->assertFalse($row->isDownloadable());
        $this->assertArrayNotHasKey('path', (new ExportResource($row))->toArray(request()));
        $this->assertArrayNotHasKey('disk', (new ExportResource($row))->toArray(request()));

        $this->artisan('model:prune', ['--model' => [DataExport::class]])->assertSuccessful();

        $this->assertNull(DataExport::query()->find($row->id));
        Storage::disk('local')->assertMissing('exports/crashed.csv');
    }

    public function test_job_claim_stores_disk_and_path_for_cleanup(): void
    {
        $id = $this->actingAs($this->user)->postJson('/exports', $this->payload(false))->json('data.id');
        $export = DataExport::findOrFail($id);

        $this->assertSame('completed', $export->status);
        $this->assertNotNull($export->disk);
        $this->assertNotNull($export->path);
    }
}
