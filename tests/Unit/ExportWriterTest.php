<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Unit;

use Alif\Export\Enums\ExportFormat;
use Alif\Export\ExportWriter;
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
        app(ExportWriter::class)->store(ExportFormat::Csv, 't', $headings, $numeric, $rows, 'local', 'o.csv');

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
        $n = app(ExportWriter::class)->store(ExportFormat::Csv, 't', ['h'], [false], [['1'], ['2'], ['3']], 'local', 'o.csv');

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
            ExportFormat::Xlsx,
            't',
            ['h'],
            [false],
            [['=SUM(A1)'], ['+1'], ['-1'], ['@x']],
            'local',
            'o.xlsx',
        );

        $sheet = IOFactory::load(Storage::disk('local')->path('o.xlsx'))->getActiveSheet();

        foreach (['A2' => '=SUM(A1)', 'A3' => '+1', 'A4' => '-1', 'A5' => '@x'] as $coord => $expected) {
            $cell = $sheet->getCell($coord);
            $this->assertSame(DataType::TYPE_STRING, $cell->getDataType(), $coord);
            $this->assertFalse($cell->isFormula(), $coord);
            $this->assertSame($expected, $cell->getValue(), $coord);
        }
    }

    public function test_xlsx_numeric_column_stays_numeric(): void
    {
        app(ExportWriter::class)->store(ExportFormat::Xlsx, 't', ['n'], [true], [['-5'], [12]], 'local', 'o.xlsx');

        $sheet = IOFactory::load(Storage::disk('local')->path('o.xlsx'))->getActiveSheet();

        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('A2')->getDataType());
        $this->assertEquals(-5, $sheet->getCell('A2')->getValue());
    }

    public function test_pdf_file_starts_with_pdf_magic(): void
    {
        $n = app(ExportWriter::class)->store(ExportFormat::Pdf, 'Title', ['h'], [false], [['a'], ['b']], 'local', 'o.pdf');

        $this->assertSame(2, $n);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get('o.pdf'));
    }

    /** Plan: "A PDF containing <img src=http://...> or <script> text renders it escaped." */
    public function test_pdf_view_escapes_html_in_cells_headings_and_title(): void
    {
        $evil = '<img src="http://evil.example/x.png">';
        $html = view('export::pdf', [
            'title' => '<script>alert(1)</script>',
            'headings' => ['<b>H</b>'],
            'numeric' => [false],
            'rows' => [[$evil], ['<script>steal()</script>']],
        ])->render();

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<b>H', $html);
        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_pdf_with_hostile_content_is_still_produced(): void
    {
        app(ExportWriter::class)->store(
            ExportFormat::Pdf,
            '<script>x</script>',
            ['h'],
            [false],
            [['<img src="http://127.0.0.1:1/evil">'], ['<script>alert(1)</script>']],
            'local',
            'o.pdf',
        );

        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get('o.pdf'));
    }
}
