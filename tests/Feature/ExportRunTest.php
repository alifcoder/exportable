<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Feature;

use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Enums\ColumnType;
use Alif\Export\Events\ExportFinished;
use Alif\Export\Events\ExportProgressed;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Jobs\RunExport;
use Alif\Export\Tests\Fixtures\DiskFileStore;
use Alif\Export\Tests\Fixtures\Order;
use Alif\Export\Tests\Fixtures\OrderFilter;
use Alif\Export\Tests\Fixtures\OrderLine;
use Alif\Export\Tests\Fixtures\User;
use Alif\Export\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** The queued job: it reads the prepared query and writes the file, with no filter, request or signed-in user. */
final class ExportRunTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Event::fake([ExportFinished::class, ExportProgressed::class]);
        $this->user = User::create(['name' => 'u']);
    }

    protected function tearDown(): void
    {
        DiskFileStore::$failPut = false;
        OrderFilter::$scopeByAuthUser = false;

        parent::tearDown();
    }

    /** @param array<string, mixed> $parameters */
    private function export(array $parameters): ExportTask
    {
        return (new OrderFilter($parameters))->export($this->user);
    }

    private function finished(): ExportFinished
    {
        $events = Event::dispatched(ExportFinished::class)->map(fn (array $args): ExportFinished => $args[0])->values();
        $this->assertCount(1, $events, 'the owner is told once');

        return $events->first();
    }

    /** @return list<list<string|null>> Rows of the written csv, heading first. */
    private function csv(?ExportFinished $event = null): array
    {
        $event ??= $this->finished();
        $this->assertTrue($event->succeeded(), (string) $event->errorCode);
        $text = Storage::disk('local')->get((string) $event->fileId);

        return array_map(
            fn (string $line): array => str_getcsv($line, escape: ''),
            array_values(array_filter(explode("\n", str_replace("\xEF\xBB\xBF", '', $text)), fn (string $l): bool => $l !== '')),
        );
    }

    /** @param list<string> $numbers */
    private function orders(array $numbers): void
    {
        foreach ($numbers as $number) {
            Order::create(['number' => $number, 'total' => '10.5']);
        }
    }

    /** @return list<string> */
    private function column(array $rows, int $index): array
    {
        return array_map(fn (array $row): string => (string) $row[$index], array_slice($rows, 1));
    }

    public function test_headings_come_from_the_request_and_values_are_read_by_resource_key(): void
    {
        Order::create(['number' => 'A-1', 'total' => '10.5', 'owner' => 'Ali']);

        $this->export(['format' => 'csv', 'columns' => [
            'prefix-index' => 'Document Number', 'owner.name' => 'Owner Name', 'total' => 'Sum', 'number' => 'Raw',
        ]]);

        $this->assertSame([['Document Number', 'Owner Name', 'Sum', 'Raw'], ['PX-A-1', 'Ali', '10.5', 'A-1']], $this->csv());
    }

    public function test_a_key_below_an_object_that_is_null_in_every_sampled_row_is_accepted_and_empty(): void
    {
        Order::create(['number' => 'A-1', 'total' => '1']);

        $this->export(['format' => 'csv', 'columns' => ['number' => 'N', 'owner.name' => 'Owner']]);

        $this->assertSame([['N', 'Owner'], ['A-1', '']], $this->csv());
    }

    public function test_the_file_is_named_after_the_source_list_and_has_the_format_extension(): void
    {
        $this->orders(['A']);

        $this->export(['format' => 'excel', 'columns' => ['number' => 'N']]);

        $event = $this->finished();
        $this->assertMatchesRegularExpression('/^order_\d{4}-\d{2}-\d{2}_\d{6}\.xlsx$/', (string) $event->fileName);
        Storage::disk('local')->assertExists((string) $event->fileId);
    }

    public function test_csv_file_has_the_csv_extension(): void
    {
        $this->orders(['A']);

        $this->export(['format' => 'csv', 'columns' => ['number' => 'N']]);

        $this->assertStringEndsWith('.csv', (string) $this->finished()->fileName);
    }

    public function test_column_types_are_read_from_the_sampled_values(): void
    {
        $this->orders(['A']);

        $task = $this->export(['format' => 'csv', 'columns' => ['number' => 'N', 'total' => 'T', 'created_at' => 'C']]);

        $this->assertSame([null, ColumnType::Number, ColumnType::DateTime], array_map(fn ($c) => $c->type, $task->request->columns));
    }

    public function test_children_are_written_one_line_per_child_and_a_childless_document_keeps_one_blank_line(): void
    {
        $a = Order::create(['number' => 'A-1', 'total' => '1']);
        Order::create(['number' => 'B-1', 'total' => '1']);
        foreach (['s1', 's2'] as $sku) {
            OrderLine::create(['order_id' => $a->id, 'sku' => $sku, 'qty' => 2]);
        }

        $task = $this->export(['format' => 'csv', 'sort' => 'number', 'columns' => ['number' => 'Number', 'lines' => ['sku' => 'SKU', 'qty' => 'Quantity']]]);

        $this->assertSame([
            ['Number', 'SKU', 'Quantity'],
            ['A-1', 's1', '2'],
            ['A-1', 's2', '2'],
            ['B-1', '', ''],
        ], $this->csv());
        $this->assertSame(3, $task->totalRows);
        $this->assertSame('lines', $task->request->childRelation);
        $this->assertSame(['sku', 'qty'], array_map(fn ($c) => $c->key, $task->request->childColumns));
    }

    public function test_chunked_paging_keeps_the_sort_order_across_chunks(): void
    {
        config(['export.chunk_size' => 2]);
        $this->orders(['E', 'B', 'D', 'A', 'C']);
        $selects = 0;
        DB::listen(function ($query) use (&$selects): void {
            if (str_contains($query->sql, 'from "orders"') && str_contains($query->sql, ' limit ')) {
                $selects++;
            }
        });

        $this->export(['format' => 'csv', 'sort' => '-number', 'columns' => ['number' => 'N']]);

        $this->assertSame(['E', 'D', 'C', 'B', 'A'], $this->column($this->csv(), 0));
        $this->assertGreaterThanOrEqual(3, $selects, 'five rows at two per chunk need three reads');
    }

    public function test_chunks_with_children_load_each_chunks_lines(): void
    {
        config(['export.chunk_size' => 2]);
        foreach (['A', 'B', 'C'] as $number) {
            $order = Order::create(['number' => $number, 'total' => '1']);
            foreach (['x', 'y'] as $sku) {
                OrderLine::create(['order_id' => $order->id, 'sku' => "$number$sku", 'qty' => 1]);
            }
        }

        $this->export(['format' => 'csv', 'sort' => 'number', 'columns' => ['number' => 'N', 'lines' => ['sku' => 'S']]]);

        $this->assertSame(['Ax', 'Ay', 'Bx', 'By', 'Cx', 'Cy'], $this->column($this->csv(), 1));
    }

    public function test_the_lists_window_is_respected_across_chunks(): void
    {
        config(['export.chunk_size' => 2]);
        $this->orders(['A', 'B', 'C', 'D', 'E', 'F', 'G']);

        $task = $this->export(['format' => 'csv', 'sort' => 'number', 'per_page' => 5, 'page' => 2, 'columns' => ['number' => 'N']]);

        $this->assertSame(['F', 'G'], $this->column($this->csv(), 0));
        $this->assertSame(2, $task->totalRows);
    }

    public function test_a_window_smaller_than_a_chunk_is_not_over_read(): void
    {
        config(['export.chunk_size' => 10]);
        $this->orders(['A', 'B', 'C', 'D']);

        $this->export(['format' => 'csv', 'sort' => 'number', 'per_page' => 3, 'columns' => ['number' => 'N']]);

        $this->assertSame(['A', 'B', 'C'], $this->column($this->csv(), 0));
    }

    public function test_filter_conditions_are_compiled_into_the_prepared_query(): void
    {
        $this->orders(['A', 'B', 'C']);

        $this->export(['format' => 'csv', 'filter' => ['number' => ['in' => ['A', 'C']]], 'sort' => 'number', 'columns' => ['number' => 'N']]);

        $this->assertSame(['A', 'C'], $this->column($this->csv(), 0));
    }

    public function test_the_access_scope_is_applied_on_the_request_and_the_job_needs_no_signed_in_user(): void
    {
        Queue::fake();
        OrderFilter::$scopeByAuthUser = true;
        $other = User::create(['name' => 'o']);
        Order::create(['number' => 'MINE-1', 'total' => '1', 'owner' => $this->user->id]);
        Order::create(['number' => 'THEIRS-1', 'total' => '3', 'owner' => $other->id]);

        $this->actingAs($this->user);
        $task = $this->export(['format' => 'csv', 'columns' => ['number' => 'N']]);
        Auth::forgetGuards();
        // The scope is no longer reachable: only the compiled query can keep the other owner's rows out.
        OrderFilter::$scopeByAuthUser = false;

        Queue::assertPushed(RunExport::class, 1);
        $job = Queue::pushed(RunExport::class)->first();
        $this->assertSame($task->toArray(), $job->task);
        app()->call([$job, 'handle']);

        $this->assertSame([['N'], ['MINE-1']], $this->csv());
        $this->assertSame(1, $this->finished()->rows);
        $this->assertNull(auth()->user());
    }

    public function test_the_task_is_scalar_and_carries_the_sql_not_the_filter_parameters(): void
    {
        $this->orders(['A']);

        $task = $this->export(['format' => 'csv', 'filter' => ['number' => 'A'], 'columns' => ['number' => 'N']]);

        $array = $task->toArray();
        $this->assertSame($array, json_decode((string) json_encode($array), true));
        $this->assertArrayNotHasKey('parameters', $array['request']);
        $this->assertArrayNotHasKey('title', $array['request']);
        $this->assertStringContainsString('from "orders"', $array['request']['query']['sql']);
        $this->assertSame(['A'], $array['request']['query']['bindings']);
    }

    public function test_progress_is_reported_with_the_counted_total_and_ends_with_100(): void
    {
        config(['export.progress' => ['enabled' => true, 'min_rows' => 1, 'step_percent' => 50]]);
        $this->orders(['A', 'B', 'C', 'D']);

        $task = $this->export(['format' => 'csv', 'columns' => ['number' => 'N']]);

        $percents = Event::dispatched(ExportProgressed::class)->map(fn (array $args): int => $args[0]->percent)->all();
        $this->assertSame([50, 100], $percents);
        $this->assertSame(4, $task->totalRows);
        Event::assertDispatched(ExportProgressed::class, fn (ExportProgressed $e): bool => $e->ownerId === (string) $this->user->getKey() && $e->totalRows === 4);
    }

    public function test_without_progress_nothing_is_counted_and_no_event_is_sent(): void
    {
        config(['export.progress' => ['enabled' => false, 'min_rows' => 1, 'step_percent' => 1]]);
        $this->orders(['A']);

        $task = $this->export(['format' => 'csv', 'columns' => ['number' => 'N']]);

        $this->assertNull($task->totalRows);
        Event::assertNotDispatched(ExportProgressed::class);
        $this->assertSame([['N'], ['A']], $this->csv());
    }

    public function test_the_quota_slot_is_released_when_the_job_ends(): void
    {
        config(['export.max_active_per_user' => 1]);
        $this->orders(['A']);

        $first = $this->export(['format' => 'csv', 'columns' => ['number' => 'N']]);
        $second = $this->export(['format' => 'csv', 'columns' => ['number' => 'N']]);

        $this->assertFalse(Cache::has("export-slot:{$first->ownerId}:{$first->id}"));
        $this->assertFalse(Cache::has("export-slot:{$second->ownerId}:{$second->id}"));
    }

    public function test_active_exports_over_the_quota_are_refused_with_429(): void
    {
        Queue::fake();
        config(['export.max_active_per_user' => 1]);
        $this->orders(['A']);
        $this->export(['format' => 'csv', 'columns' => ['number' => 'N']]);

        try {
            $this->export(['format' => 'csv', 'columns' => ['number' => 'N']]);
            $this->fail('The second export was accepted.');
        } catch (ExportException $e) {
            $this->assertSame(429, $e->httpStatus());
        }
    }

    public function test_a_store_that_cannot_keep_the_file_fails_the_export_frees_the_slot_and_is_reported(): void
    {
        Exceptions::fake();
        DiskFileStore::$failPut = true;
        $this->orders(['A']);

        $task = $this->export(['format' => 'csv', 'columns' => ['number' => 'N']]);

        $this->assertSame('storage_write_failed', $this->finished()->errorCode);
        $this->assertFalse(Cache::has("export-slot:{$task->ownerId}:{$task->id}"));
        Exceptions::assertReported(fn (ExportException $e): bool => $e->errorCode === 'storage_write_failed');
    }

    public function test_the_local_temporary_file_is_always_removed(): void
    {
        $this->orders(['A']);
        $before = glob(sys_get_temp_dir().'/export_*') ?: [];

        $this->export(['format' => 'csv', 'columns' => ['number' => 'N']]);
        DiskFileStore::$failPut = true;
        Exceptions::fake();
        $this->export(['format' => 'csv', 'columns' => ['number' => 'N']]);

        $this->assertSame($before, glob(sys_get_temp_dir().'/export_*') ?: []);
    }

    public function test_rows_that_outgrow_the_cap_after_submit_fail_the_export_and_keep_no_file(): void
    {
        Queue::fake();
        $this->orders(['A', 'B']);
        $task = $this->export(['format' => 'csv', 'columns' => ['number' => 'N']]);
        config(['export.max_rows.csv' => 1]);

        app()->call([Queue::pushed(RunExport::class)->first(), 'handle']);

        $this->assertSame('row_limit_exceeded', $this->finished()->errorCode);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertFalse(Cache::has("export-slot:{$task->ownerId}:{$task->id}"));
    }

    public function test_an_unexpected_failure_becomes_export_failed_and_is_reported(): void
    {
        Queue::fake();
        Exceptions::fake();
        $this->orders(['A']);
        $this->export(['format' => 'csv', 'columns' => ['number' => 'N']]);
        Schema::drop('orders');

        app()->call([Queue::pushed(RunExport::class)->first(), 'handle']);

        $this->assertSame('export_failed', $this->finished()->errorCode);
        Exceptions::assertReported(QueryException::class);
    }

    public function test_a_throwing_listener_never_fails_the_job_and_is_reported(): void
    {
        Exceptions::fake();
        $this->app->instance('events', new Dispatcher($this->app));
        Event::clearResolvedInstance('events');
        Event::listen(ExportFinished::class, fn () => throw new RuntimeException('mail down'));
        $this->orders(['A']);

        $this->export(['format' => 'csv', 'columns' => ['number' => 'N']]);

        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'mail down');
    }

    public function test_the_slot_is_released_once_even_if_failed_is_called_afterwards(): void
    {
        Queue::fake();
        Exceptions::fake();
        $this->orders(['A']);
        $task = $this->export(['format' => 'csv', 'columns' => ['number' => 'N']]);
        $job = Queue::pushed(RunExport::class)->first();

        app()->call([$job, 'handle']);
        $job->failed(new RuntimeException('late'));

        $this->assertFalse(Cache::has("export-slot:{$task->ownerId}:{$task->id}"));
        $this->assertCount(1, Event::dispatched(ExportFinished::class), 'the owner is told once');
    }

    public function test_failed_hook_announces_a_failure_and_frees_the_slot(): void
    {
        Queue::fake();
        Exceptions::fake();
        $this->orders(['A']);
        $task = $this->export(['format' => 'csv', 'columns' => ['number' => 'N']]);

        Queue::pushed(RunExport::class)->first()->failed(new RuntimeException('killed'));

        $this->assertSame('export_failed', $this->finished()->errorCode);
        $this->assertFalse(Cache::has("export-slot:{$task->ownerId}:{$task->id}"));
    }

    public function test_the_event_can_be_switched_off_by_config(): void
    {
        config(['export.events.finished' => false]);
        $this->orders(['A']);

        $this->export(['format' => 'csv', 'columns' => ['number' => 'N']]);

        Event::assertNotDispatched(ExportFinished::class);
    }
}
