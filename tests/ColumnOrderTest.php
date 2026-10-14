<?php

declare(strict_types=1);

namespace Alif\Export\Tests;

use Alif\Export\DTO\Export\ExportColumn;
use Alif\Export\Helpers\ColumnNode;
use Alif\Export\Helpers\DocumentReader;
use Alif\Export\Helpers\Writers\CsvWriter;
use ReflectionMethod;

final class ColumnOrderTest extends TestCase
{
    /** @return list<ExportColumn> */
    private function columns(): array
    {
        return [
            new ExportColumn('number', 'No', null, []),
            new ExportColumn('quantity', 'Qty', null, ['lines']),
            new ExportColumn('date', 'Date', null, []),
            new ExportColumn('product.name', 'Product', null, ['lines']),
            new ExportColumn('imei', 'IMEI', null, ['lines', 'serials']),
            new ExportColumn('note', 'Note', null, ['notes']),
            new ExportColumn('name', 'Name', null, ['lines']),
        ];
    }

    public function test_columns_and_sibling_lists_keep_the_order_the_client_sent(): void
    {
        $tree = ColumnNode::tree($this->columns());

        $this->assertSame(['No', 'Date'], $tree->headings());
        $this->assertSame(['lines', 'notes'], array_keys($tree->children));
        $this->assertSame(['Qty', 'Product', 'Name'], $tree->children['lines']->headings());
    }

    public function test_written_file_follows_the_client_order(): void
    {
        $read = [
            'values' => ['date' => 'D1', 'number' => 'N1'],
            'lists' => [
                'notes' => [['values' => ['note' => 'n'], 'lists' => []]],
                'lines' => [['values' => ['name' => 'nm', 'product.name' => 'P', 'quantity' => 5], 'lists' => [
                    'serials' => [['values' => ['imei' => 'I1'], 'lists' => []]],
                ]]],
            ],
        ];
        config(['export' => require __DIR__.'/../config/export.php']);
        $method = new ReflectionMethod(DocumentReader::class, 'lines');
        $lines = $method->invoke(new DocumentReader, ColumnNode::tree($this->columns()), $read);

        $path = tempnam(sys_get_temp_dir(), 'ord');
        (new CsvWriter)->write(['No', 'Date'], [], $lines, $path);
        $csv = file_get_contents($path);
        unlink($path);

        $this->assertSame(
            ['No,Date', 'N1,D1', ',Qty,Product,Name', ',5,P,nm', ',,IMEI', ',,I1', ',Note', ',n'],
            array_values(array_filter(array_map(trim(...), preg_split('/\R/', ltrim($csv, "\xEF\xBB\xBF"))))),
        );
    }

    public function test_a_list_column_the_item_does_not_yield_is_written_empty(): void
    {
        $tree = ColumnNode::tree([
            new ExportColumn('number', 'No', null, []),
            new ExportColumn('quantity', 'Qty', null, ['lines']),
            new ExportColumn('ghost', 'Ghost', null, ['lines']),
        ]);
        $read = ['values' => ['number' => 'N1'], 'lists' => ['lines' => [['values' => ['quantity' => 5], 'lists' => []]]]];

        config(['export' => require __DIR__.'/../config/export.php']);
        $lines = (new ReflectionMethod(DocumentReader::class, 'lines'))->invoke(new DocumentReader, $tree, $read);

        $this->assertSame([5, null], $lines[2]->cells);
    }
}
