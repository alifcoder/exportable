<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Unit;

use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Enums\ExportFormat;
use PHPUnit\Framework\TestCase;
use ValueError;

final class ExportDtoTest extends TestCase
{
    /** @return array<string, mixed> */
    private function row(): array
    {
        return [
            'exportable' => 'orders',
            'format' => 'xlsx',
            'columns' => ['number', 'total'],
            'include_children' => true,
            'child_columns' => ['sku'],
            'title' => 'My title',
            'parameters' => ['sort' => 'number'],
        ];
    }

    public function test_create_dto_round_trips_through_array(): void
    {
        $dto = ExportCreateDTO::fromArray($this->row());

        $this->assertSame(ExportFormat::XLSX, $dto->format);
        $this->assertTrue($dto->includeChildren);
        $this->assertSame($this->row(), $dto->toArray());
        $this->assertEquals($dto, ExportCreateDTO::fromArray($dto->toArray()));
    }

    public function test_create_dto_defaults_optional_keys(): void
    {
        $data = $this->row();
        unset($data['child_columns'], $data['title'], $data['parameters']);

        $dto = ExportCreateDTO::fromArray($data);

        $this->assertSame([], $dto->childColumns);
        $this->assertNull($dto->title);
        $this->assertSame([], $dto->parameters);
    }

    public function test_create_dto_reindexes_column_lists(): void
    {
        $data = $this->row();
        $data['columns'] = [3 => 'a', 7 => 'b'];
        $data['child_columns'] = ['x' => 'c'];

        $dto = ExportCreateDTO::fromArray($data);

        $this->assertSame(['a', 'b'], $dto->columns);
        $this->assertSame(['c'], $dto->childColumns);
    }

    public function test_create_dto_rejects_an_unknown_format(): void
    {
        $data = $this->row();
        $data['format'] = 'pdf';

        $this->expectException(ValueError::class);
        ExportCreateDTO::fromArray($data);
    }

    public function test_create_dto_casts_include_children_to_bool(): void
    {
        $data = $this->row();
        $data['include_children'] = 0;

        $this->assertFalse(ExportCreateDTO::fromArray($data)->includeChildren);
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
