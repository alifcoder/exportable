<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Feature;

use Alif\Export\Contracts\Exportable;
use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\Enums\ExportFormat;
use Alif\Export\Helpers\Column;
use Alif\Export\Helpers\ExportBuilder;
use Alif\Export\Helpers\ExportPlan;
use Alif\Export\Tests\Fixtures\Order;
use Alif\Export\Tests\Fixtures\OrderExportable;
use Alif\Export\Tests\Fixtures\OrderFilter;
use Alif\Export\Tests\Fixtures\OrderLine;
use Alif\Export\Tests\TestCase;
use Alif\QueryFilter\Interfaces\EBFilterInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class ExportBuilderChunksTest extends TestCase
{
    private function plan(array $parameters = []): ExportPlan
    {
        return ExportPlan::for(new OrderExportable, new ExportCreateDTO('orders', ExportFormat::CSV, ['number'], false, [], null, $parameters));
    }

    /** @return list<string> */
    private function numbers(array $parameters = []): array
    {
        $builder = new ExportBuilder;
        $plan = $this->plan($parameters);

        return array_map(fn (array $row): string => $row[0], iterator_to_array($builder->rows($plan, $builder->query($plan)), false));
    }

    public function test_rows_keep_order_across_chunk_boundaries(): void
    {
        config()->set('export.chunk_size', 2);
        foreach (['E', 'D', 'C', 'B', 'A'] as $n) {
            Order::create(['number' => $n, 'total' => '1']);
        }

        $this->assertSame(['A', 'B', 'C', 'D', 'E'], $this->numbers(['sort' => 'number']));
    }

    public function test_row_deleted_during_export_is_skipped(): void
    {
        config()->set('export.chunk_size', 1);
        Order::create(['number' => 'A', 'total' => '1']);
        $second = Order::create(['number' => 'B', 'total' => '1']);
        Order::create(['number' => 'C', 'total' => '1']);

        $builder = new ExportBuilder;
        $plan = $this->plan();
        $rows = $builder->rows($plan, $builder->query($plan));

        $this->assertSame('A', $rows->current()[0]);
        $second->delete();
        $rows->next();

        $this->assertSame('C', $rows->current()[0]);
    }

    public function test_filter_limit_applies_to_the_snapshot_not_each_chunk(): void
    {
        config()->set('export.chunk_size', 2);
        foreach (['A', 'B', 'C', 'D'] as $n) {
            Order::create(['number' => $n, 'total' => '1']);
        }

        $this->assertSame(['A', 'B', 'C'], $this->numbers(['sort' => 'number', 'limit' => 3]));
    }

    public function test_join_duplicating_documents_does_not_duplicate_exported_rows(): void
    {
        config()->set('export.chunk_size', 2);
        $a = Order::create(['number' => 'A', 'total' => '1']);
        Order::create(['number' => 'B', 'total' => '1']);
        foreach (['x', 'y', 'z'] as $sku) {
            OrderLine::create(['order_id' => $a->id, 'sku' => $sku]);
        }

        $exportable = $this->exportable(fn (): Builder => Order::query()->join('order_lines', 'order_lines.order_id', '=', 'orders.id')->select('orders.*'));
        $plan = ExportPlan::for($exportable, new ExportCreateDTO('orders', ExportFormat::CSV, ['number'], false, [], null, ['sort' => 'number']));
        $builder = new ExportBuilder;

        $numbers = array_map(fn (array $row): string => $row[0], iterator_to_array($builder->rows($plan, $builder->query($plan)), false));

        $this->assertSame(['A'], $numbers);
    }

    public function test_submit_time_count_uses_the_model_connection_not_the_default(): void
    {
        $connection = Order::query()->getModel()->getConnectionName() ?? config('database.default');
        $order = Order::create(['number' => 'A', 'total' => '1']);
        OrderLine::create(['order_id' => $order->id, 'sku' => 'x']);
        OrderLine::create(['order_id' => $order->id, 'sku' => 'y']);

        $exportable = $this->exportable(fn (): Builder => Order::on($connection));
        $plan = ExportPlan::for($exportable, new ExportCreateDTO('orders', ExportFormat::CSV, ['number'], true, ['sku'], null, []));
        $builder = new ExportBuilder;
        $query = $builder->query($plan);

        config()->set('database.default', 'nowhere');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Too many rows');
        config()->set('export.max_rows.csv', 1);
        $builder->assertWithinCap($plan, $query);
    }

    private function exportable(\Closure $query): Exportable
    {
        return new class($query) implements Exportable
        {
            public function __construct(private readonly \Closure $query) {}

            public function title(): string
            {
                return 'Orders';
            }

            public function columns(): array
            {
                return ['number' => Column::make('Number')];
            }

            public function childRelation(): ?string
            {
                return 'lines';
            }

            public function childColumns(): array
            {
                return ['sku' => Column::make('SKU')];
            }

            public function query(): Builder
            {
                return ($this->query)();
            }

            public function filter(array $parameters): EBFilterInterface
            {
                return new OrderFilter($parameters);
            }
        };
    }
}
