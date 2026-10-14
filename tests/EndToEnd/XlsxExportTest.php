<?php

declare(strict_types=1);

namespace Alif\Export\Tests\EndToEnd;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class XlsxExportTest extends EndToEndTestCase
{
    private function sheet(): Worksheet
    {
        $path = $this->tempFile($this->lastFile(), 'xlsx');
        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);

        return $sheet;
    }

    private function text(Worksheet $sheet, string $coordinate): string
    {
        return (string) $sheet->getCell($coordinate)->getValue();
    }

    public function test_header_values_and_cell_types(): void
    {
        $this->seedOrders(2);

        $task = $this->export(['format' => 'excel', 'columns' => [
            'number' => 'No', 'document_date' => 'Date', 'created_at' => 'Created', 'total_amount' => 'Total', 'is_paid' => 'Paid', 'id' => 'Id',
        ]]);

        $sheet = $this->sheet();

        $this->assertSame("{$task->id}.xlsx", $this->finished[0]->fileName);
        $this->assertSame(['No', 'Date', 'Created', 'Total', 'Paid', 'Id'], array_map(fn (string $c): string => $this->text($sheet, $c.'1'), ['A', 'B', 'C', 'D', 'E', 'F']));
        $this->assertSame('ORD-00001', $this->text($sheet, 'A2'));

        // A date and a date-time are real Excel dates with the configured formats.
        $this->assertTrue(Date::isDateTime($sheet->getCell('B2')));
        $this->assertSame('yyyy-mm-dd', $sheet->getStyle('B2')->getNumberFormat()->getFormatCode());
        $this->assertTrue(Date::isDateTime($sheet->getCell('C2')));
        $this->assertSame('yyyy-mm-dd hh:mm:ss', $sheet->getStyle('C2')->getNumberFormat()->getFormatCode());
        $this->assertSame('2026-03-04 05:06:07', Date::excelToDateTimeObject($sheet->getCell('C2')->getValue())->format('Y-m-d H:i:s'));

        // A decimal and an integer are numbers with their formats; a boolean is the configured text.
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('D2')->getDataType());
        $this->assertEqualsWithDelta(1.5, $sheet->getCell('D2')->getValue(), 0.0001);
        $this->assertSame('#,##0.00', $sheet->getStyle('D2')->getNumberFormat()->getFormatCode());
        $this->assertSame('No', $this->text($sheet, 'E2'));
        $this->assertSame('Yes', $this->text($sheet, 'E3'));
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('F2')->getDataType());
        $this->assertSame('#,##0', $sheet->getStyle('F2')->getNumberFormat()->getFormatCode());
    }

    public function test_the_heading_is_styled_from_the_config(): void
    {
        $this->seedOrders(1);

        $this->export(['format' => 'excel', 'columns' => ['number' => 'No']]);

        $style = $this->sheet()->getStyle('A1');

        $this->assertTrue($style->getFont()->getBold());
        $this->assertSame('FFE8EEF7', $style->getFill()->getStartColor()->getARGB());
        $this->assertSame('Calibri', $style->getFont()->getName());
    }

    public function test_text_is_never_a_formula(): void
    {
        $this->seedOrders(1, ['comment' => '=SUM(1+1)']);

        $this->export(['format' => 'excel', 'columns' => ['comment' => 'C']]);

        $cell = $this->sheet()->getCell('A2');

        $this->assertContains($cell->getDataType(), [DataType::TYPE_STRING, DataType::TYPE_INLINE], 'Text is a string cell, never a formula.');
        $this->assertNotSame(DataType::TYPE_FORMULA, $cell->getDataType());
        $this->assertSame('=SUM(1+1)', (string) $cell->getValue());
    }

    public function test_lists_are_sub_rows_grouped_in_a_collapsed_outline(): void
    {
        $this->seedOrders(1, [], lines: 2, serials: 1);

        $this->export(['format' => 'excel', 'columns' => [
            'number' => 'No',
            'lines' => ['sequence' => '#', 'product.name' => 'Product', 'serials' => ['imei' => 'IMEI']],
        ]]);

        $sheet = $this->sheet();

        // 1 heading, 1 document, then per list a heading and the items, each item followed by its own list.
        $this->assertSame('ORD-00001', $this->text($sheet, 'A2'));
        $this->assertSame('#', $this->text($sheet, 'B3'));
        $this->assertSame(1, $sheet->getCell('B4')->getValue());
        $this->assertSame('P1', $this->text($sheet, 'C4'));
        $this->assertSame('IMEI', $this->text($sheet, 'C5'));
        $this->assertSame('IMEI-1-1-1', $this->text($sheet, 'C6'));

        $this->assertSame(0, $sheet->getRowDimension(2)->getOutlineLevel());
        $this->assertSame(1, $sheet->getRowDimension(3)->getOutlineLevel());
        $this->assertSame(1, $sheet->getRowDimension(4)->getOutlineLevel());
        $this->assertSame(2, $sheet->getRowDimension(5)->getOutlineLevel());
        $this->assertSame(2, $sheet->getRowDimension(6)->getOutlineLevel());
        $this->assertTrue($sheet->getRowDimension(3)->getCollapsed() || ! $sheet->getRowDimension(3)->getVisible());
    }

    public function test_column_width_follows_the_longest_value_within_the_limits(): void
    {
        $this->seedOrders(1, ['comment' => str_repeat('x', 200)]);

        $this->export(['format' => 'excel', 'columns' => ['number' => 'N', 'comment' => 'Comment']]);

        $sheet = $this->sheet();

        $this->assertEqualsWithDelta(14, $sheet->getColumnDimension('A')->getWidth(), 1.5, 'min 10 / value + padding 4');
        $this->assertEqualsWithDelta(60, $sheet->getColumnDimension('B')->getWidth(), 1.5, 'clamped to max 60');
    }

    public function test_a_file_over_several_chunks_has_every_row_once(): void
    {
        config(['export.chunk_size' => 100]);
        $this->seedOrders(1234);

        $this->export(['format' => 'excel', 'columns' => ['number' => 'No', 'id' => 'Id']]);

        $sheet = $this->sheet();
        $numbers = [];

        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $numbers[] = $this->text($sheet, 'A'.$row);
        }

        $this->assertCount(1234, $numbers);
        $this->assertSame(1234, count(array_unique($numbers)));
        $this->assertSame('ORD-00001', $numbers[0]);
        $this->assertSame('ORD-01234', $numbers[1233]);
        $this->assertSame(1234, $this->finished[0]->rows);
    }

    public function test_the_xlsx_mime_type(): void
    {
        $this->seedOrders(1);

        $task = $this->export(['format' => 'excel', 'columns' => ['number' => 'No']]);

        $this->assertSame("{$task->id}|{$task->id}.xlsx|application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", array_key_first(self::$files));
    }
}
