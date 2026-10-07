<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Stress;

use Alif\Export\Entities\DataExport;
use Alif\Export\Helpers\ExportRegistry;
use Alif\Export\Tests\Fixtures\OrderExportable;
use Alif\Export\Tests\Fixtures\User;
use Alif\Export\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Not part of the default run. Execute with:
 *   STRESS_ROWS=50000 STRESS_MEMORY_MB=512 vendor/bin/phpunit --group stress
 * STRESS_ROWS is the number of documents (2 child lines each) for xlsx, STRESS_CSV_ROWS for csv.
 * Add TEST_DB_HOST=127.0.0.1 TEST_DB_PORT=55432 to run on Postgres instead of in-memory sqlite.
 * Uses the fake local disk, so it measures the SDK and the chosen database, not real storage.
 */
#[Group('stress')]
final class ExportStressTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function formats(): array
    {
        return ['csv' => ['csv'], 'xlsx' => ['xlsx']];
    }

    #[DataProvider('formats')]
    public function test_export_stays_within_time_and_memory_budget(string $format): void
    {
        $documents = match ($format) {
            'csv' => (int) (getenv('STRESS_CSV_ROWS') ?: getenv('STRESS_ROWS') ?: 25000),
            default => (int) (getenv('STRESS_ROWS') ?: 25000),
        };
        $memoryBudgetMb = (int) (getenv('STRESS_MEMORY_MB') ?: 512);

        Storage::fake('local');
        app(ExportRegistry::class)->register('orders', OrderExportable::class);
        Gate::define('data-export', fn (): bool => true);
        config(['export.max_rows.csv' => PHP_INT_MAX, 'export.max_rows.xlsx' => PHP_INT_MAX]);
        $user = User::create(['name' => 'u']);

        $this->seedOrders($documents);
        gc_collect_cycles();
        $before = memory_get_peak_usage(true);
        $started = hrtime(true);

        $response = $this->actingAs($user)->postJson('/exports', [
            'exportable' => 'orders',
            'file' => ['format' => $format, 'columns' => ['number', 'total'], 'include_children' => true, 'child_columns' => ['sku', 'qty']],
        ]);

        $seconds = (hrtime(true) - $started) / 1e9;
        $peakMb = memory_get_peak_usage(true) / 1048576;
        $export = DataExport::findOrFail($response->json('data.id'));
        $size = Storage::disk('local')->size((string) $export->path);

        fwrite(STDERR, sprintf(
            "\n[stress] %-4s docs=%d rows=%d time=%.1fs peak=%.0fMB (baseline %.0fMB) file=%.1fMB\n",
            $format, $documents, (int) $export->rows_count, $seconds, $peakMb, $before / 1048576, $size / 1048576,
        ));

        $this->assertSame('completed', $export->status, (string) $export->error_code);
        $this->assertSame($documents * 2, $export->rows_count);
        $this->assertLessThan($memoryBudgetMb, $peakMb, "peak memory {$peakMb}MB over {$memoryBudgetMb}MB budget");
    }

    private function seedOrders(int $documents): void
    {
        $now = now()->toDateTimeString();

        foreach (array_chunk(range(1, $documents), 500) as $chunk) {
            $orders = [];
            $lines = [];
            foreach ($chunk as $i) {
                $id = (string) Str::uuid();
                $orders[] = ['id' => $id, 'number' => "N-{$i}", 'total' => '123.456789', 'created_at' => $now, 'updated_at' => $now];
                foreach (['a', 'b'] as $sku) {
                    $lines[] = ['id' => (string) Str::uuid(), 'order_id' => $id, 'sku' => "{$sku}-{$i}", 'qty' => 2, 'created_at' => $now, 'updated_at' => $now];
                }
            }
            DB::table('orders')->insert($orders);
            DB::table('order_lines')->insert($lines);
        }
    }
}
