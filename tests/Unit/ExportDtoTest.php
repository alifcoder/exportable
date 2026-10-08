<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Unit;

use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\DTO\Export\ExportDownloadDTO;
use Alif\Export\DTO\Export\ExportListDTO;
use Alif\Export\Enums\ExportFormat;
use Alif\Export\Enums\ExportStatus;
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

    public function test_list_dto_defaults(): void
    {
        $dto = new ExportListDTO('owner-1');

        $this->assertSame('owner-1', $dto->ownerId);
        $this->assertNull($dto->status);
        $this->assertNull($dto->exportable);
        $this->assertSame(20, $dto->perPage);
    }

    public function test_list_dto_carries_filters(): void
    {
        $dto = new ExportListDTO('o', ExportStatus::FAILED, 'orders', 5);

        $this->assertSame(ExportStatus::FAILED, $dto->status);
        $this->assertSame('orders', $dto->exportable);
        $this->assertSame(5, $dto->perPage);
    }

    public function test_download_dto_carries_its_four_fields(): void
    {
        $dto = new ExportDownloadDTO('local', 'exports/a.csv', 'orders.csv', 'text/csv');

        $this->assertSame(['local', 'exports/a.csv', 'orders.csv', 'text/csv'], [$dto->disk, $dto->path, $dto->fileName, $dto->mimeType]);
    }
}
