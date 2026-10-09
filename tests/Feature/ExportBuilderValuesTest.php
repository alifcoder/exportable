<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Feature;

use Alif\Export\Contracts\Exportable;
use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\Enums\ExportFormat;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\Column;
use Alif\Export\Helpers\ExportBuilder;
use Alif\Export\Helpers\ExportPlan;
use Alif\Export\Tests\Fixtures\Order;
use Alif\Export\Tests\Fixtures\OrderExportable;
use Alif\Export\Tests\Fixtures\OrderFilter;
use Alif\Export\Tests\Fixtures\OrderLine;
use Alif\Export\Tests\TestCase;
use Alif\QueryFilter\Interfaces\EBFilterInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

enum Level: string
{
    case High = 'high';
}

final class ExportBuilderValuesTest extends TestCase
{
    /** @param array<string, Column> $columns */
    private function exportable(array $columns, ?string $relation = null, ?callable $query = null): Exportable
    {
        return new class($columns, $relation, $query) implements Exportable
        {
            /** @param array<string, Column> $columns */
            public function __construct(private array $columns, private ?string $relation, private $query) {}

            public function title(): string
            {
                return 'T';
            }

            public function columns(): array
            {
                return $this->columns;
            }

            public function childRelation(): ?string
            {
                return $this->relation;
            }

            public function childColumns(): array
            {
                return [];
            }

            public function query(array $parameters): Builder
            {
                return $this->query ? ($this->query)() : Order::query();
            }

            public function filter(array $parameters): EBFilterInterface
            {
                return new OrderFilter($parameters);
            }
        };
    }

    /** @param array<string, Column> $columns @return list<list<mixed>> */
    private function rows(array $columns): array
    {
        $builder = new ExportBuilder;
        $plan = ExportPlan::for($this->exportable($columns), new ExportCreateDTO('x', ExportFormat::CSV, array_keys($columns), false, [], null, []));

        return iterator_to_array($builder->rows($plan, $builder->query($plan)), false);
    }

    public function test_values_are_normalised_for_every_supported_type(): void
    {
        Order::create(['number' => 'A', 'total' => 1]);

        $rows = $this->rows([
            'null' => Column::make('n', fn () => null),
            'enum' => Column::make('e', fn () => Level::High),
            'date' => Column::make('d', fn () => Carbon::parse('2026-01-02 03:04:05')),
            'immutable' => Column::make('i', fn () => new CarbonImmutable('2026-05-06 07:08:09')),
            'true' => Column::make('t', fn () => true),
            'false' => Column::make('f', fn () => false),
            'int' => Column::make('int', fn () => 7),
            'float' => Column::make('fl', fn () => 1.5),
            'string' => Column::make('s', fn () => 'text'),
            'empty' => Column::make('es', fn () => ''),
        ]);

        $this->assertSame([[null, 'high', '2026-01-02 03:04:05', '2026-05-06 07:08:09', '1', '0', 7, 1.5, 'text', '']], $rows);
    }

    public function test_default_column_reads_the_attribute_named_by_the_key(): void
    {
        Order::create(['number' => 'A-9', 'total' => 1]);

        $this->assertSame([['A-9']], $this->rows(['number' => Column::make('n')]));
    }

    /** @return array<string, array{callable}> */
    public static function nonScalar(): array
    {
        return [
            'array' => [fn () => ['a']],
            'object' => [fn () => new \stdClass],
            'model' => [fn () => new Order],
            'closure' => [fn () => fn () => 1],
        ];
    }

    #[DataProvider('nonScalar')]
    public function test_non_scalar_values_fail_with_invalid_column_value_naming_the_column(callable $value): void
    {
        Order::create(['number' => 'A', 'total' => 1]);

        try {
            $this->rows(['bad' => Column::make('b', $value)]);
            $this->fail('Expected ExportException');
        } catch (ExportException $e) {
            $this->assertSame('invalid_column_value', $e->errorCode);
            $this->assertStringContainsString('"bad"', $e->getMessage());
        }
    }

    public function test_no_documents_yield_no_rows(): void
    {
        $this->assertSame([], $this->rows(['number' => Column::make('n')]));
    }

    public function test_default_order_is_by_primary_key_when_the_filter_does_not_sort(): void
    {
        $ids = [];
        foreach (['x', 'y', 'z'] as $n) {
            $ids[] = Order::create(['number' => $n, 'total' => 1])->id;
        }
        sort($ids);

        $this->assertSame(array_map(fn (string $id): array => [$id], $ids), $this->rows(['id' => Column::make('id')]));
    }

    // ---- row cap ---------------------------------------------------------------

    private function capPlan(string $format = 'csv', bool $children = false): ExportPlan
    {
        return ExportPlan::for(new OrderExportable, new ExportCreateDTO('orders', ExportFormat::from($format), ['number'], $children, $children ? ['sku'] : [], null, []));
    }

    public function test_assert_within_cap_passes_at_the_cap_and_fails_one_over(): void
    {
        config(['export.max_rows.csv' => 2]);
        $builder = new ExportBuilder;
        Order::create(['number' => 'a', 'total' => 1]);
        Order::create(['number' => 'b', 'total' => 1]);
        $plan = $this->capPlan();

        $builder->assertWithinCap($plan, $builder->query($plan));
        $this->addToAssertionCount(1);

        Order::create(['number' => 'c', 'total' => 1]);

        try {
            $builder->assertWithinCap($plan, $builder->query($plan));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('maximum 2', $e->errors()['file.format'][0]);
            $this->assertStringContainsString('csv', $e->errors()['file.format'][0]);
        }
    }

    public function test_assert_within_cap_counts_a_childless_document_as_one_row_and_children_individually(): void
    {
        config(['export.max_rows.csv' => 4]);
        $builder = new ExportBuilder;
        Order::create(['number' => 'empty', 'total' => 1]);
        $withKids = Order::create(['number' => 'kids', 'total' => 1]);
        foreach (['a', 'b', 'c'] as $sku) {
            OrderLine::create(['order_id' => $withKids->id, 'sku' => $sku]);
        }
        $plan = $this->capPlan('csv', true);

        $builder->assertWithinCap($plan, $builder->query($plan)); // 1 + 3 = 4, at the cap
        $this->addToAssertionCount(1);

        OrderLine::create(['order_id' => $withKids->id, 'sku' => 'd']);
        $this->expectException(ValidationException::class);
        $builder->assertWithinCap($plan, $builder->query($plan));
    }

    public function test_rows_throws_row_limit_exceeded_when_output_passes_the_cap_and_children_count_as_rows(): void
    {
        config(['export.max_rows.csv' => 2]);
        $builder = new ExportBuilder;
        $o = Order::create(['number' => 'a', 'total' => 1]);
        foreach (['a', 'b', 'c'] as $sku) {
            OrderLine::create(['order_id' => $o->id, 'sku' => $sku]);
        }
        $plan = $this->capPlan('csv', true);

        try {
            iterator_to_array($builder->rows($plan, $builder->query($plan)));
            $this->fail('Expected ExportException');
        } catch (ExportException $e) {
            $this->assertSame('row_limit_exceeded', $e->errorCode);
            $this->assertStringContainsString('2 row limit', $e->getMessage());
        }
    }

    public function test_rows_at_exactly_the_cap_succeed(): void
    {
        config(['export.max_rows.csv' => 2]);
        Order::create(['number' => 'a', 'total' => 1]);
        Order::create(['number' => 'b', 'total' => 1]);
        $builder = new ExportBuilder;
        $plan = $this->capPlan();

        $this->assertCount(2, iterator_to_array($builder->rows($plan, $builder->query($plan)), false));
    }

    public function test_cap_is_per_format(): void
    {
        config(['export.max_rows.csv' => 1, 'export.max_rows.xlsx' => 5]);
        Order::create(['number' => 'a', 'total' => 1]);
        Order::create(['number' => 'b', 'total' => 1]);
        $builder = new ExportBuilder;

        $xlsx = $this->capPlan('xlsx');
        $builder->assertWithinCap($xlsx, $builder->query($xlsx));
        $this->addToAssertionCount(1);

        $csv = $this->capPlan('csv');
        $this->expectException(ValidationException::class);
        $builder->assertWithinCap($csv, $builder->query($csv));
    }

    // ---- children --------------------------------------------------------------

    public function test_non_has_many_child_relation_is_an_invalid_registration(): void
    {
        $exportable = $this->exportable(['number' => Column::make('n')], 'bogus');
        $plan = ExportPlan::for($exportable, new ExportCreateDTO('x', ExportFormat::CSV, ['number'], true, [], null, []));

        try {
            (new ExportBuilder)->query($plan);
            $this->fail('Expected ExportException');
        } catch (ExportException $e) {
            $this->assertSame('invalid_registration', $e->errorCode);
            $this->assertNull($e->httpStatus());
        }
    }

    public function test_child_relation_that_is_not_has_many_is_rejected(): void
    {
        $exportable = $this->exportable(['number' => Column::make('n')], 'getKey');
        $plan = ExportPlan::for($exportable, new ExportCreateDTO('x', ExportFormat::CSV, ['number'], true, [], null, []));

        $this->expectException(ExportException::class);
        (new ExportBuilder)->query($plan);
    }

    public function test_lazy_loading_prevention_applies_to_each_row_when_armed(): void
    {
        Order::create(['number' => 'a', 'total' => 1]);
        Model::preventLazyLoading();
        $this->beforeApplicationDestroyed(fn () => Model::preventLazyLoading(false));

        $this->expectException(LazyLoadingViolationException::class);
        $this->rows(['x' => Column::make('x', fn (Order $o) => $o->lines->count())]);
    }
}
