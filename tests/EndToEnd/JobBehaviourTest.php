<?php

declare(strict_types=1);

namespace Alif\Export\Tests\EndToEnd;

use Alif\Export\Events\ExportProgressed;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Jobs\RunExport;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cache;

final class JobBehaviourTest extends EndToEndTestCase
{
    /** @return list<string> */
    private function tempFiles(): array
    {
        return glob(sys_get_temp_dir().'/export_*') ?: [];
    }

    public function test_no_row_is_repeated_or_skipped_across_chunks(): void
    {
        config(['export.chunk_size' => 37]);
        // Every row shares its sort value: only the key keeps the chunks apart.
        $this->seedOrders(500);
        OrderFilter::$sort = ['created_at', 'id'];

        $this->export(['format' => 'csv', 'columns' => ['number' => 'No']]);

        $lines = array_filter(explode("\n", ltrim($this->lastFile(), "\xEF\xBB\xBF")));
        array_shift($lines);

        $this->assertSame(array_map(fn (int $i): string => sprintf('ORD-%05d', $i), range(1, 500)), array_values($lines));
    }

    public function test_the_temporary_file_is_removed(): void
    {
        $before = $this->tempFiles();
        $this->seedOrders(3);

        $this->export(['format' => 'csv', 'columns' => ['number' => 'No']]);
        $this->export(['format' => 'excel', 'columns' => ['number' => 'No']]);

        $this->assertSame($before, $this->tempFiles());
    }

    public function test_a_resource_that_fails_while_writing_is_announced_as_failed(): void
    {
        config(['export.chunk_size' => 100]);
        $this->seedOrders(300);
        // Beyond the rows sampled for the column check.
        OrderResource::$explodeAt = 'ORD-00250';
        $before = $this->tempFiles();

        $this->export(['format' => 'csv', 'columns' => ['number' => 'No']]);

        $this->assertCount(1, $this->finished);
        $this->assertFalse($this->finished[0]->succeeded());
        $this->assertSame('resource_failed', $this->finished[0]->errorCode);
        $this->assertNull($this->finished[0]->fileId);
        $this->assertEmpty(self::$files);
        $this->assertSame($before, $this->tempFiles());
    }

    public function test_a_resource_that_fails_on_a_sampled_row_refuses_the_request(): void
    {
        $this->seedOrders(10);
        OrderResource::$explodeAt = 'ORD-00003';

        try {
            $this->export(['format' => 'csv', 'columns' => ['number' => 'No']]);
            $this->fail('The resource fails on a sampled row.');
        } catch (ExportException $e) {
            $this->assertSame('resource_failed', $e->errorCode);
            $this->assertSame(500, $e->status);
            $this->assertEmpty($this->finished);
        }
    }

    public function test_a_store_that_fails_is_announced_as_storage_write_failed(): void
    {
        $this->seedOrders(3);
        self::$storeFails = true;
        $before = $this->tempFiles();

        $this->export(['format' => 'csv', 'columns' => ['number' => 'No']]);

        $this->assertSame('storage_write_failed', $this->finished[0]->errorCode);
        $this->assertNull($this->finished[0]->fileId);
        $this->assertSame($before, $this->tempFiles());
    }

    public function test_rows_written_over_the_cap_fail_the_job(): void
    {
        // 10 documents pass the request check (cap counts documents there), their sub rows do not fit while writing.
        config(['export.max_rows.csv' => 30]);
        $this->seedOrders(10, [], lines: 3);

        $this->export(['format' => 'csv', 'columns' => ['number' => 'No', 'lines' => ['sequence' => '#']]]);

        $this->assertSame('row_limit_exceeded', $this->finished[0]->errorCode);
        $this->assertEmpty(self::$files);
    }

    public function test_the_finished_event_can_be_switched_off(): void
    {
        config(['export.events.finished' => false]);
        $this->seedOrders(2);

        $this->export(['format' => 'csv', 'columns' => ['number' => 'No']]);

        $this->assertEmpty($this->finished);
        $this->assertCount(1, self::$files, 'The file is still written and stored.');
    }

    public function test_a_failure_reaching_the_job_twice_is_announced_once(): void
    {
        $this->seedOrders(2);
        $task = $this->export(['format' => 'csv', 'columns' => ['number' => 'No']]);

        $job = new RunExport($task->toArray());
        $job->failed(new \RuntimeException('killed'));

        $this->assertCount(1, $this->finished, 'The success was announced already; the failure hook is silent.');
        $this->assertTrue($this->finished[0]->succeeded());
        $this->assertTrue(Cache::has("export-finished:{$task->id}"));
    }

    public function test_a_killed_job_is_announced_as_failed_with_a_general_code(): void
    {
        $job = new RunExport(['id' => 'abc', 'request' => ['format' => 'csv']]);

        $job->failed(new \RuntimeException('killed'));

        $this->assertSame('export_failed', $this->finished[0]->errorCode);
        $this->assertSame('abc', $this->finished[0]->task->id);
    }

    public function test_progress_is_reported_up_to_one_hundred_percent(): void
    {
        config(['export.chunk_size' => 50, 'export.progress.min_rows' => 10, 'export.progress.step_percent' => 10]);
        $this->seedOrders(400);

        $this->export(['format' => 'csv', 'columns' => ['number' => 'No']]);

        $percents = array_map(fn ($e): int => $e->percent, $this->progress);

        $this->assertNotEmpty($percents);
        $this->assertSame(100, end($percents));
        $this->assertSame($percents, array_values(array_unique($percents)));
        $sorted = $percents;
        sort($sorted);
        $this->assertSame($sorted, $percents, 'Progress only goes forward.');
        $this->assertSame(400, end($this->progress)->totalRows);
        $this->assertSame(400, end($this->progress)->rows);
    }

    public function test_no_progress_is_reported_for_a_small_export(): void
    {
        config(['export.progress.min_rows' => 1000]);
        $this->seedOrders(20);

        $this->export(['format' => 'csv', 'columns' => ['number' => 'No']]);

        $this->assertEmpty($this->progress);
    }

    public function test_a_missing_setting_in_the_job_is_announced_as_invalid_configuration(): void
    {
        config(['export.chunk_size' => null]);
        $this->seedOrders(1);

        $this->export(['format' => 'csv', 'columns' => ['number' => 'No']]);

        $this->assertSame('invalid_configuration', $this->finished[0]->errorCode);
    }

    public function test_a_missing_setting_on_the_request_names_the_key(): void
    {
        $config = config('export');
        unset($config['progress']);
        config(['export' => $config]);
        $this->seedOrders(1);

        try {
            $this->export(['format' => 'csv', 'columns' => ['number' => 'No']]);
            $this->fail('A missing setting must fail.');
        } catch (ExportException $e) {
            $this->assertSame('invalid_configuration', $e->errorCode);
            $this->assertSame(500, $e->status);
            $this->assertStringContainsString('export.progress.enabled', $e->getMessage());
        }
    }

    public function test_a_resource_that_lazy_loads_a_relation_fails_instead_of_running_a_query_per_row(): void
    {
        $this->seedOrders(5);
        $filter = new class(['format' => 'csv', 'columns' => ['number' => 'No', 'partner_name' => 'Partner']]) extends OrderFilter
        {
            public static string $resource = LazyOrderResource::class;

            public function apply(Builder $query): void {}
        };

        try {
            $filter->export();
            $this->fail('A relation the filter does not eager load must not be lazy loaded.');
        } catch (ExportException $e) {
            $this->assertSame('resource_failed', $e->errorCode);
            $this->assertSame(500, $e->status);
        }
    }

    public function test_the_progress_event_is_a_private_broadcast_to_the_export_channel(): void
    {
        $event = new ExportProgressed('abc', 40, 400, 1000);

        $this->assertInstanceOf(PrivateChannel::class, $event->broadcastOn());
        $this->assertSame('private-export.abc', $event->broadcastOn()->name);
        $this->assertSame('export.progressed', $event->broadcastAs());
        $this->assertSame(['id' => 'abc', 'percent' => 40, 'rows' => 400, 'total_rows' => 1000], $event->broadcastWith());
        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);
    }
}

class LazyOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        return ['number' => $this->number, 'partner_name' => $this->partner->name];
    }
}
