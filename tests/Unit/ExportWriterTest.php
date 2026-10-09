<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Unit;

use Alif\Export\Enums\ExportFormat;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportWriter;
use Alif\Export\Tests\TestCaseWithoutDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class ExportWriterTest extends TestCaseWithoutDatabase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /** @param list<list<mixed>> $rows */
    private function csv(array $headings, array $numeric, array $rows): string
    {
        app(ExportWriter::class)->store(ExportFormat::CSV, 't', $headings, $numeric, $rows, Storage::disk('local')->path('o.csv'));

        return Storage::disk('local')->get('o.csv');
    }

    /** @return list<list<string>> */
    private function parse(string $csv): array
    {
        $csv = substr($csv, 3);
        $rows = [];
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $csv);
        rewind($handle);
        while (($r = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rows[] = $r;
        }

        return $rows;
    }

    public function test_csv_starts_with_utf8_bom_then_header_row(): void
    {
        $csv = $this->csv(['A', 'B'], [false, false], [['x', 'y']]);

        $this->assertStringStartsWith("\xEF\xBB\xBF".'A,B', $csv);
        $this->assertSame([['A', 'B'], ['x', 'y']], $this->parse($csv));
    }

    public function test_csv_follows_rfc4180_quoting(): void
    {
        $csv = $this->csv(['h'], [false], [['a,b'], ['say "hi"'], ["line1\nline2"]]);

        $this->assertStringContainsString('"a,b"', $csv);
        $this->assertStringContainsString('"say ""hi"""', $csv);
        $this->assertSame([['h'], ['a,b'], ['say "hi"'], ["line1\nline2"]], $this->parse($csv));
    }

    public function test_csv_returns_row_count_excluding_header(): void
    {
        $n = app(ExportWriter::class)->store(ExportFormat::CSV, 't', ['h'], [false], [['1'], ['2'], ['3']], Storage::disk('local')->path('o.csv'));

        $this->assertSame(3, $n);
    }

    public function test_csv_text_cells_starting_with_formula_characters_are_prefixed(): void
    {
        foreach (['=SUM(A1)', '+1', '-1', '@cmd', "\tx", "\rx"] as $value) {
            $parsed = $this->parse($this->csv(['h'], [false], [[$value]]));

            $this->assertSame("'".$value, $parsed[1][0], 'cell: '.json_encode($value));
        }
    }

    public function test_csv_plain_text_and_empty_cells_are_not_prefixed(): void
    {
        $parsed = $this->parse($this->csv(['h', 'i'], [false, false], [['abc', 'z'], ['5-3', 'z'], ['', 'z']]));

        $this->assertSame('abc', $parsed[1][0]);
        $this->assertSame('5-3', $parsed[2][0]);
        $this->assertSame('', $parsed[3][0]);
    }

    /** Plan: "Numeric columns are never prefixed." */
    public function test_csv_numeric_values_in_numeric_columns_are_never_prefixed(): void
    {
        $parsed = $this->parse($this->csv(['n'], [true], [[-5], ['-5'], ['-5.250000'], [-0.5], ['+7']]));

        $this->assertSame('-5', $parsed[1][0]);
        $this->assertSame('-5', $parsed[2][0]);
        $this->assertSame('-5.250000', $parsed[3][0]);
        $this->assertSame('-0.5', $parsed[4][0]);
        $this->assertSame('+7', $parsed[5][0]);
    }

    /** Plan: "In xlsx the same value is a string cell, not a formula." */
    public function test_xlsx_formula_like_and_plus_text_are_string_cells(): void
    {
        app(ExportWriter::class)->store(
            ExportFormat::XLSX,
            't',
            ['h'],
            [false],
            [['=SUM(A1)'], ['+1'], ['-1'], ['@x']],
            Storage::disk('local')->path('o.xlsx'),
        );

        $sheet = IOFactory::load(Storage::disk('local')->path('o.xlsx'))->getActiveSheet();

        foreach (['A2' => '=SUM(A1)', 'A3' => '+1', 'A4' => '-1', 'A5' => '@x'] as $coord => $expected) {
            $cell = $sheet->getCell($coord);
            $this->assertContains($cell->getDataType(), [DataType::TYPE_STRING, DataType::TYPE_INLINE], $coord); // text, never a formula
            $this->assertFalse($cell->isFormula(), $coord);
            $this->assertSame($expected, (string) $cell->getValue(), $coord);
        }
    }

    public function test_xlsx_numeric_column_stays_numeric(): void
    {
        app(ExportWriter::class)->store(ExportFormat::XLSX, 't', ['n'], [true], [['-5'], [12]], Storage::disk('local')->path('o.xlsx'));

        $sheet = IOFactory::load(Storage::disk('local')->path('o.xlsx'))->getActiveSheet();

        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('A2')->getDataType());
        $this->assertEquals(-5, $sheet->getCell('A2')->getValue());
    }

    public function test_an_unwritable_path_throws_instead_of_reporting_success(): void
    {
        foreach ([ExportFormat::CSV, ExportFormat::XLSX] as $format) {
            try {
                app(ExportWriter::class)->store($format, 't', ['A'], [false], [['x']], '/nonexistent-dir/o.'.$format->value);
                $this->fail("{$format->value} writer ignored a failed write");
            } catch (ExportException $e) {
                $this->assertSame('storage_write_failed', $e->errorCode);
            }
        }
    }
}
