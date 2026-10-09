<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Feature;

use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\Enums\ExportFormat;
use Alif\Export\Helpers\ExportBuilder;
use Alif\Export\Helpers\ExportPlan;
use Alif\Export\Helpers\ResourceExportable;
use Alif\Export\Tests\Fixtures\Order;
use Alif\Export\Tests\Fixtures\OrderFilter;
use Alif\Export\Tests\Fixtures\OrderResource;
use Alif\Export\Tests\Fixtures\SharedKeyOrderResource;
use Alif\Export\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

final class ResourceExportableTest extends TestCase
{
    private function exportable(): ResourceExportable
    {
        return new ResourceExportable(
            'Orders',
            Order::class,
            OrderResource::class,
            fn (array $parameters) => new OrderFilter($parameters),
            fn (array $parameters) => Order::query(),
            ['lines'],
        );
    }

    /** @return list<list<mixed>> */
    private function rows(ResourceExportable $exportable, array $columns, array $childColumns = [], array $parameters = []): array
    {
        $builder = new ExportBuilder;
        $plan = ExportPlan::for($exportable, new ExportCreateDTO('x', ExportFormat::CSV, $columns, $childColumns !== [], $childColumns, null, $parameters));

        return iterator_to_array($builder->rows($plan, $builder->query($plan)), false);
    }

    public function test_columns_and_children_are_discovered_from_the_resource(): void
    {
        $order = Order::create(['number' => 'A-1', 'total' => 5, 'owner' => 'Bob']);
        $order->lines()->create(['sku' => 'x', 'qty' => 2]);

        $exportable = $this->exportable();

        $this->assertSame(['id', 'number', 'total', 'owner.name'], array_keys($exportable->columns()));
        $this->assertSame('lines', $exportable->childRelation());
        $this->assertSame(['sku', 'qty'], array_keys($exportable->childColumns()));
        $this->assertTrue($exportable->columns()['total']->isNumeric());
        $this->assertTrue($exportable->childColumns()['qty']->isNumeric());
        $this->assertFalse($exportable->columns()['number']->isNumeric());
        $this->assertSame('Owner Name', $exportable->columns()['owner.name']->label());
    }

    public function test_children_flatten_with_document_cells_repeated_and_lazy_loading_blocked(): void
    {
        $a = Order::create(['number' => 'A-1', 'total' => 5, 'owner' => 'Bob']);
        $a->lines()->create(['sku' => 'x', 'qty' => 2]);
        $a->lines()->create(['sku' => 'y', 'qty' => 3]);
        Order::create(['number' => 'B-2', 'total' => 1]);

        Model::preventLazyLoading();

        try {
            $rows = $this->rows($this->exportable(), ['number', 'owner.name'], ['sku', 'qty']);
        } finally {
            Model::preventLazyLoading(false);
        }

        $this->assertEqualsCanonicalizing([
            ['A-1', 'Bob', 'x', 2],
            ['A-1', 'Bob', 'y', 3],
            ['B-2', null, null, null],
        ], $rows);
    }

    public function test_a_child_key_shared_with_a_document_key_keeps_both_columns_and_distinct_headings(): void
    {
        $order = Order::create(['number' => 'A-1', 'total' => 5]);
        $order->lines()->create(['sku' => 'x', 'qty' => 2]);

        $exportable = new ResourceExportable(
            'Orders',
            Order::class,
            SharedKeyOrderResource::class,
            fn (array $parameters) => new OrderFilter($parameters),
            fn (array $parameters) => Order::query(),
            ['lines'],
        );

        $labels = array_map(fn ($c): string => $c->label(), [...array_values($exportable->columns()), ...array_values($exportable->childColumns())]);
        $this->assertSame(['Number', 'Qty', 'Line Qty', 'Sku'], $labels);

        $builder = new ExportBuilder;
        $plan = ExportPlan::for($exportable, new ExportCreateDTO('x', ExportFormat::CSV, ['number', 'qty'], true, ['qty', 'sku'], null, []));

        $this->assertSame(['Number', 'Qty', 'Line Qty', 'Sku'], $plan->headings());
        $this->assertSame([['A-1', 5, 2, 'x']], iterator_to_array($builder->rows($plan, $builder->query($plan)), false));
    }

    public function test_the_host_filter_still_applies(): void
    {
        Order::create(['number' => 'A-1', 'total' => 5]);
        Order::create(['number' => 'B-2', 'total' => 1]);

        $rows = $this->rows($this->exportable(), ['number'], [], ['filter' => ['number' => ['eq' => 'B-2']]]);

        $this->assertSame([['B-2']], $rows);
    }

    public function test_an_empty_table_still_yields_a_usable_definition(): void
    {
        $keys = array_keys($this->exportable()->columns());

        $this->assertContains('number', $keys);
        $this->assertContains('total', $keys);
    }

    public function test_the_base_query_comes_from_the_host_and_receives_the_parameters(): void
    {
        Order::create(['number' => 'A-1', 'total' => 5]);
        Order::create(['number' => 'B-2', 'total' => 1]);
        $seen = null;
        $exportable = new ResourceExportable(
            'Orders',
            Order::class,
            OrderResource::class,
            fn (array $parameters) => new OrderFilter($parameters),
            function (array $parameters) use (&$seen) {
                $seen = $parameters;

                return Order::query()->where('total', '>', 2);
            },
            ['lines'],
        );

        $rows = $this->rows($exportable, ['number'], [], ['filter' => ['number' => ['eq' => 'A-1']]]);

        $this->assertSame([['A-1']], $rows);
        $this->assertSame(['filter' => ['number' => ['eq' => 'A-1']]], $seen);
    }

    public function test_the_column_set_is_stable_and_shared_through_the_cache(): void
    {
        foreach (range(1, 30) as $i) {
            Order::create(['number' => "N-$i", 'total' => $i, 'owner' => $i === 30 ? 'Bob' : null]);
        }
        $first = array_keys($this->exportable()->columns());

        // Another instance (a worker) reads the discovered keys from the cache, whatever the rows are now.
        Order::query()->delete();
        $second = array_keys($this->exportable()->columns());

        $this->assertSame($first, $second);
        $this->assertContains('number', $first);
    }

    public function test_discovery_is_deterministic_and_nested_keys_win_over_a_flat_null(): void
    {
        // Only the last row (by key) has the owner object; the sample is ordered, never arbitrary.
        Order::create(['number' => 'A-1', 'total' => 1]);
        Order::create(['number' => 'B-2', 'total' => 2, 'owner' => 'Bob']);

        $keys = array_keys($this->exportable()->columns());

        $this->assertContains('owner.name', $keys);
        $this->assertNotContains('owner', $keys);
    }

    public function test_keyed_and_closure_relations_are_kept_for_eager_loading(): void
    {
        $order = Order::create(['number' => 'A-1', 'total' => 5]);
        $order->lines()->create(['sku' => 'x', 'qty' => 2]);
        $order->lines()->create(['sku' => 'y', 'qty' => 3]);
        $exportable = new ResourceExportable(
            'Orders',
            Order::class,
            OrderResource::class,
            fn (array $parameters) => new OrderFilter($parameters),
            fn (array $parameters) => Order::query(),
            ['lines' => fn ($q) => $q->where('sku', 'y')],
        );

        $rows = $this->rows($exportable, ['number'], ['sku']);

        $this->assertSame([['A-1', 'y']], $rows);
    }
}
