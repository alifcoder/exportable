<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Unit;

use Alif\Export\DTO\Export\ExportColumn;
use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\DTO\Export\PreparedQuery;
use Alif\Export\Enums\ColumnType;
use Alif\Export\Enums\ExportFormat;
use Alif\Export\Tests\Fixtures\OrderFilter;
use PHPUnit\Framework\TestCase;
use ValueError;

final class ExportDtoTest extends TestCase
{
    /** @return array<string, mixed> */
    private function row(): array
    {
        return [
            'source' => OrderFilter::class,
            'format' => 'excel',
            'columns' => [['key' => 'number', 'label' => 'Document Number', 'type' => null], ['key' => 'total', 'label' => 'Total', 'type' => 'number']],
            'child_relation' => 'lines',
            'child_columns' => [['key' => 'sku', 'label' => 'SKU', 'type' => null]],
            'query' => ['connection' => null, 'sql' => 'select * from "orders" order by "orders"."id" asc', 'bindings' => ['a', 1], 'offset' => 5, 'limit' => 10],
        ];
    }

    public function test_create_dto_round_trips_through_array(): void
    {
        $dto = ExportCreateDTO::fromArray($this->row());

        $this->assertSame(ExportFormat::EXCEL, $dto->format);
        $this->assertSame('lines', $dto->childRelation);
        $this->assertEquals(new ExportColumn('total', 'Total', ColumnType::Number), $dto->columns[1]);
        $this->assertSame($this->row(), $dto->toArray());
        $this->assertEquals($dto, ExportCreateDTO::fromArray($dto->toArray()));
    }

    public function test_create_dto_carries_no_title_and_no_filter_parameters(): void
    {
        $this->assertSame(
            ['source', 'format', 'columns', 'child_relation', 'child_columns', 'query'],
            array_keys(ExportCreateDTO::fromArray($this->row())->toArray()),
        );
    }

    public function test_create_dto_rejects_an_unknown_format(): void
    {
        $data = $this->row();
        $data['format'] = 'xlsx';

        $this->expectException(ValueError::class);
        ExportCreateDTO::fromArray($data);
    }

    public function test_prepared_query_keeps_the_window_apart_from_the_sql(): void
    {
        $query = PreparedQuery::fromArray($this->row()['query']);

        $this->assertSame(5, $query->offset);
        $this->assertSame(10, $query->limit);
        $this->assertSame(['a', 1], $query->bindings);
        $this->assertSame($this->row()['query'], $query->toArray());
    }

    public function test_task_round_trips_through_a_scalar_array(): void
    {
        $task = new ExportTask('id-1', 'owner-1', 'uz', ExportCreateDTO::fromArray($this->row()), 42);

        $array = $task->toArray();

        $this->assertSame($array, json_decode((string) json_encode($array), true));
        $this->assertEquals($task, ExportTask::fromArray($array));
    }

    public function test_task_total_rows_may_be_unknown(): void
    {
        $task = new ExportTask('id-1', 'owner-1', 'en', ExportCreateDTO::fromArray($this->row()), null);

        $this->assertNull(ExportTask::fromArray($task->toArray())->totalRows);
    }
}
