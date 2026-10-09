<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Unit;

use Alif\Export\Helpers\Writers\CsvWriter;
use Alif\Export\Helpers\Writers\XlsxWriter;
use Alif\Export\Tests\TestCaseWithoutDatabase;
use Generator;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class ExportWriterEdgeCasesTest extends TestCaseWithoutDatabase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /** @param list<list<mixed>> $rows */
    private function csv(array $headings, array $numeric, iterable $rows, string $path = 'o.csv'): string
    {
        (new CsvWriter)->write('t', $headings, $numeric, $rows, Storage::disk('local')->path($path));

        return substr(Storage::disk('local')->get($path), 3);
    }

    /** @param list<list<mixed>> $rows */
    private function sheet(array $headings, array $numeric, iterable $rows, int &$count = 0): Worksheet
    {
        $count = (new XlsxWriter)->write('t', $headings, $numeric, $rows, Storage::disk('local')->path('o.xlsx'));

        return IOFactory::load(Storage::disk('local')->path('o.xlsx'))->getActiveSheet();
    }

    // ---- csv ---------------------------------------------------------------

    public function test_csv_with_no_rows_has_bom_and_header_only_and_returns_zero(): void
    {
        $count = (new CsvWriter)->write('t', ['A', 'B'], [false, false], [], Storage::disk('local')->path('o.csv'));

        $this->assertSame(0, $count);
        $this->assertSame("\xEF\xBB\xBFA,B\n", Storage::disk('local')->get('o.csv'));
    }

    public function test_csv_unicode_survives_byte_for_byte(): void
    {
        $values = ['Тошкент шаҳри', 'O‘zbekiston', '日本語', 'émoji 😀', 'ñandú'];
        $out = $this->csv(['h'], [false], array_map(fn (string $v): array => [$v], $values));

        foreach ($values as $v) {
            $this->assertStringContainsString($v, $out);
        }
        $this->assertTrue(mb_check_encoding($out, 'UTF-8'));
    }

    public function test_csv_null_becomes_an_empty_cell(): void
    {
        $out = $this->csv(['a', 'b', 'c'], [false, false, false], [['x', null, 'z']]);

        $this->assertSame("a,b,c\nx,,z\n", $out);
    }

    public function test_csv_backslash_is_not_an_escape_character(): void
    {
        $out = $this->csv(['h'], [false], [['a\\"b'], ['c\\']]);

        $this->assertStringContainsString('"a\\""b"', $out);
        $this->assertStringContainsString("c\\\n", $out);
    }

    public function test_csv_numeric_flag_does_not_exempt_text_that_is_not_a_number(): void
    {
        $out = $this->csv(['n'], [true], [['=1+1'], ['-abc'], ['@x'], ['+SUM(1)']]);

        $this->assertSame("n\n'=1+1\n'-abc\n'@x\n'+SUM(1)\n", $out);
    }

    public function test_csv_numeric_column_keeps_scientific_and_decimal_numbers_unprefixed(): void
    {
        $out = $this->csv(['n'], [true], [['-1.5e3'], ['+0.5'], [-0.0], [0]]);

        $this->assertStringContainsString("-1.5e3\n", $out);
        $this->assertStringContainsString("+0.5\n", $out);
        $this->assertStringNotContainsString("'", $out);
    }

    public function test_csv_large_numbers_are_written_exactly(): void
    {
        $out = $this->csv(['n'], [true], [['12345678901234567890.123456'], [PHP_INT_MAX], ['-99999999999999999999']]);

        $this->assertStringContainsString("12345678901234567890.123456\n", $out);
        $this->assertStringContainsString((string) PHP_INT_MAX."\n", $out);
        $this->assertStringContainsString("-99999999999999999999\n", $out);
    }

    public function test_csv_mid_string_formula_characters_are_left_alone(): void
    {
        $out = $this->csv(['h'], [false], [['a=b'], ['x+y'], ['a@b.c'], [' =1']]);

        $this->assertStringNotContainsString("'", $out);
    }

    public function test_csv_streams_a_generator_and_counts_every_row(): void
    {
        $gen = (function (): Generator {
            for ($i = 0; $i < 2500; $i++) {
                yield [(string) $i];
            }
        })();

        $count = (new CsvWriter)->write('t', ['h'], [false], $gen, Storage::disk('local')->path('o.csv'));

        $this->assertSame(2500, $count);
        $this->assertCount(2501, explode("\n", trim(Storage::disk('local')->get('o.csv'))));
    }

    public function test_csv_overwrites_an_existing_file_instead_of_appending(): void
    {
        $this->csv(['h'], [false], [['first']]);
        $out = $this->csv(['h'], [false], [['second']]);

        $this->assertStringNotContainsString('first', $out);
        $this->assertStringContainsString('second', $out);
    }

    // ---- xlsx --------------------------------------------------------------

    public function test_xlsx_with_no_rows_has_only_the_header_and_returns_zero(): void
    {
        $count = 99;
        $sheet = $this->sheet(['A', 'B'], [false, false], [], $count);

        $this->assertSame(0, $count);
        $this->assertSame('A', (string) $sheet->getCell('A1')->getValue());
        $this->assertSame('B', (string) $sheet->getCell('B1')->getValue());
        $this->assertSame(1, $sheet->getHighestRow());
    }

    public function test_xlsx_unicode_round_trips(): void
    {
        $sheet = $this->sheet(['Сарлавҳа'], [false], [['Тошкент 😀'], ['日本語']]);

        $this->assertSame('Сарлавҳа', (string) $sheet->getCell('A1')->getValue());
        $this->assertSame('Тошкент 😀', (string) $sheet->getCell('A2')->getValue());
        $this->assertSame('日本語', (string) $sheet->getCell('A3')->getValue());
    }

    public function test_xlsx_null_cells_are_empty_strings_not_the_word_null(): void
    {
        $sheet = $this->sheet(['a', 'b'], [false, true], [['x', null], [null, null]]);

        $this->assertSame('x', (string) $sheet->getCell('A2')->getValue());
        $this->assertContains((string) $sheet->getCell('B2')->getValue(), ['']);
        $this->assertSame('', (string) $sheet->getCell('A3')->getValue());
    }

    public function test_xlsx_numbers_up_to_15_digits_stay_numeric_and_longer_become_exact_text(): void
    {
        $sheet = $this->sheet(['n'], [true], [['123456789012345'], ['1234567890123456'], ['12345678901234567890'], [PHP_INT_MAX], ['0.123456789012345'], ['0.1234567890123456']]);

        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('A2')->getDataType());
        foreach (['A3' => '1234567890123456', 'A4' => '12345678901234567890', 'A5' => (string) PHP_INT_MAX, 'A7' => '0.1234567890123456'] as $coord => $text) {
            $this->assertNotSame(DataType::TYPE_NUMERIC, $sheet->getCell($coord)->getDataType(), $coord);
            $this->assertSame($text, (string) $sheet->getCell($coord)->getValue(), $coord);
        }
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('A6')->getDataType());
    }

    public function test_xlsx_leading_zeros_do_not_count_toward_the_precision_limit(): void
    {
        $sheet = $this->sheet(['n'], [true], [['0000000000000000001']]);

        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('A2')->getDataType());
        $this->assertEquals(1, $sheet->getCell('A2')->getValue());
    }

    public function test_xlsx_non_numeric_text_in_a_numeric_column_stays_text(): void
    {
        $sheet = $this->sheet(['n'], [true], [['abc'], ['=1+1'], ['']]);

        $this->assertSame('abc', (string) $sheet->getCell('A2')->getValue());
        $this->assertFalse($sheet->getCell('A3')->isFormula());
        $this->assertSame('=1+1', (string) $sheet->getCell('A3')->getValue());
    }

    public function test_xlsx_numeric_text_in_a_text_column_is_not_converted(): void
    {
        $sheet = $this->sheet(['code'], [false], [['007'], ['1.50']]);

        $this->assertSame('007', (string) $sheet->getCell('A2')->getValue());
        $this->assertSame('1.50', (string) $sheet->getCell('A3')->getValue());
    }

    public function test_xlsx_negative_decimal_and_float_values(): void
    {
        $sheet = $this->sheet(['n'], [true], [['-10.500000'], [3.25], [-7]]);

        $this->assertEquals(-10.5, $sheet->getCell('A2')->getValue());
        $this->assertEquals(3.25, $sheet->getCell('A3')->getValue());
        $this->assertEquals(-7, $sheet->getCell('A4')->getValue());
    }

    public function test_xlsx_heading_that_looks_like_a_formula_is_text(): void
    {
        $sheet = $this->sheet(['=1+1'], [false], []);

        $this->assertFalse($sheet->getCell('A1')->isFormula());
        $this->assertSame('=1+1', (string) $sheet->getCell('A1')->getValue());
    }

    public function test_xlsx_many_rows_are_all_written(): void
    {
        $count = 0;
        $gen = (function (): Generator {
            for ($i = 1; $i <= 1500; $i++) {
                yield [$i];
            }
        })();

        $sheet = $this->sheet(['n'], [true], $gen, $count);

        $this->assertSame(1500, $count);
        $this->assertSame(1501, $sheet->getHighestRow());
        $this->assertEquals(1500, $sheet->getCell('A1501')->getValue());
    }

    public function test_xlsx_row_failure_propagates(): void
    {
        $gen = (function (): Generator {
            yield ['x'];

            throw new \RuntimeException('boom');
        })();

        $this->expectException(\RuntimeException::class);
        (new XlsxWriter)->write('t', ['h'], [false], $gen, Storage::disk('local')->path('o.xlsx'));
    }

    public function test_xlsx_uses_the_configured_formats_header_style_and_widths(): void
    {
        config([
            'export.style.xlsx.number_format' => '0.0',
            'export.style.xlsx.integer_format' => '0000',
            'export.style.xlsx.header.background' => 'FFFF0000',
            'export.style.xlsx.header.bold' => true,
            'export.style.xlsx.column_width' => ['min' => 12, 'max' => 20, 'padding' => 2],
        ]);

        (new XlsxWriter)->write('t', ['Short', 'A very very long heading indeed'], [true, true], [[7, 1.5]], Storage::disk('local')->path('o.xlsx'));
        $sheet = IOFactory::load(Storage::disk('local')->path('o.xlsx'))->getActiveSheet();

        $this->assertSame('0000', $sheet->getStyle('A2')->getNumberFormat()->getFormatCode());
        $this->assertSame('0.0', $sheet->getStyle('B2')->getNumberFormat()->getFormatCode());
        $this->assertTrue($sheet->getStyle('A1')->getFont()->getBold());
        $this->assertSame('FFFF0000', $sheet->getStyle('A1')->getFill()->getStartColor()->getARGB());
        $this->assertEqualsWithDelta(12, $sheet->getColumnDimension('A')->getWidth(), 0.5);
        $this->assertEqualsWithDelta(20, $sheet->getColumnDimension('B')->getWidth(), 0.5);
    }
}
