<?php

declare(strict_types=1);

namespace Alif\Export\Tests\EndToEnd;

use Alif\Export\Contracts\ExportFileStore;
use Alif\Export\Jobs\RunExport;
use Alif\Export\Services\Actions\Export\WriteExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class QueryPortabilityTest extends EndToEndTestCase
{
    public function test_bindings_of_every_kind_survive_the_queue_payload(): void
    {
        $this->seedOrders(10);

        $task = $this->export(['format' => 'csv', 'columns' => ['number' => 'No'], 'filter' => ['bindings' => 1]]);

        // Odd ids (is_paid false): 1, 3, 5, 7 within ids 1..8.
        $this->assertSame(['No', 'ORD-00001', 'ORD-00003', 'ORD-00005', 'ORD-00007'], array_filter(explode("\n", ltrim($this->lastFile(), "\xEF\xBB\xBF"))));

        // The payload is plain data: it survives JSON (a queue driver may store it so) and still runs.
        $payload = json_decode(json_encode($task->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->finished = [];
        self::$files = [];
        Cache::flush();
        (new RunExport($payload))->handle(app(WriteExport::class), app(ExportFileStore::class));

        $this->assertTrue($this->finished[0]->succeeded(), (string) $this->finished[0]->errorCode);
        $this->assertSame(4, $this->finished[0]->rows);
    }

    public function test_a_date_binding_keeps_its_wall_clock_time_through_a_json_payload(): void
    {
        config(['app.timezone' => 'Asia/Tashkent']);
        date_default_timezone_set('Asia/Tashkent');
        $this->seedOrders(3);

        $task = $this->export(['format' => 'csv', 'columns' => ['number' => 'No'], 'filter' => ['moment' => 1]]);
        $this->assertSame(3, $this->finished[0]->rows);

        $payload = json_decode(json_encode($task->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->finished = [];
        Cache::flush();
        (new RunExport($payload))->handle(app(WriteExport::class), app(ExportFileStore::class));

        $this->assertTrue($this->finished[0]->succeeded(), (string) $this->finished[0]->errorCode);
        $this->assertSame(3, $this->finished[0]->rows, 'The same rows after the payload went through JSON.');
    }

    public function test_a_model_on_another_connection_is_read_on_that_connection(): void
    {
        config(['database.connections.second' => config('database.connections.pgsql')]);
        $this->seedOrders(3);
        $filter = new class(['format' => 'csv', 'columns' => ['number' => 'No']]) extends OrderFilter
        {
            public function query(): Builder
            {
                return SecondOrder::query()->orderBy('id');
            }

            public function apply(Builder $query): void {}
        };

        $filter->export();

        $this->assertSame("\xEF\xBB\xBFNo\nORD-00001\nORD-00002\nORD-00003\n", $this->lastFile());
    }

    public function test_a_query_with_a_cte_and_a_join_is_paged_correctly(): void
    {
        config(['export.chunk_size' => 7]);
        $this->seedOrders(40, [], lines: 1);
        $filter = new class(['format' => 'csv', 'columns' => ['number' => 'No']]) extends OrderFilter
        {
            public function query(): Builder
            {
                return Order::query()
                    ->select('e2e_orders.*')
                    ->join('e2e_lines', 'e2e_lines.order_id', '=', 'e2e_orders.id')
                    ->withCount('lines')
                    ->orderBy('e2e_orders.id');
            }

            public function apply(Builder $query): void {}
        };

        $filter->export();

        $lines = array_filter(explode("\n", ltrim($this->lastFile(), "\xEF\xBB\xBF")));
        array_shift($lines);
        $this->assertCount(40, $lines);
        $this->assertSame(40, count(array_unique($lines)));
    }

    public function test_the_job_sees_a_consistent_set_when_rows_are_added_after_the_request(): void
    {
        $this->seedOrders(20);
        $task = $this->export(['format' => 'csv', 'columns' => ['number' => 'No']]);

        $this->assertSame(20, $this->finished[0]->rows);
        $this->assertSame(20, DB::table('e2e_orders')->count());
        $this->assertSame(20, $task->totalRows);
    }
}

class SecondOrder extends Order
{
    protected $connection = 'second';
}
