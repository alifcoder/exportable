<?php

declare(strict_types=1);

namespace Alif\Export\Tests\EndToEnd;

use Alif\QueryFilter\Enums\FieldType;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class EdgeCasesTest extends EndToEndTestCase
{
    private function sheet(): Worksheet
    {
        $path = $this->tempFile($this->lastFile(), 'xlsx');
        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);

        return $sheet;
    }

    /** @return list<list<string|null>> */
    private function csv(): array
    {
        $rows = [];
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, ltrim($this->lastFile(), "\xEF\xBB\xBF"));
        rewind($stream);

        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function test_numbers_over_fifteen_digits_are_kept_exact_as_text(): void
    {
        OrderResource::$extra = ['big' => 12345678901234567890, 'bigtext' => '12345678901234567890', 'neg' => -5, 'frac_amount' => '-0.25', 'zero' => 0, 'ean' => '00123'];
        $this->seedOrders(1);
        $columns = ['big' => 'B', 'bigtext' => 'BT', 'neg' => 'N', 'frac_amount' => 'F', 'zero' => 'Z', 'ean' => 'E'];

        $this->export(['format' => 'excel', 'columns' => $columns]);
        $sheet = $this->sheet();

        $this->assertSame('1.2345678901234567E+19', (string) $sheet->getCell('A2')->getValue(), 'A float over 15 digits is exact text, not a rounded number.');
        $this->assertSame('12345678901234567890', (string) $sheet->getCell('B2')->getValue());
        $this->assertSame(-5, $sheet->getCell('C2')->getValue());
        $this->assertEqualsWithDelta(-0.25, $sheet->getCell('D2')->getValue(), 0.00001);
        $this->assertSame('00123', (string) $sheet->getCell('F2')->getValue(), 'A text with leading zeros is not a number.');

        $this->finished = [];
        self::$files = [];
        $this->export(['format' => 'csv', 'columns' => $columns]);
        $row = $this->csv()[1];

        $this->assertSame('12345678901234567890', $row[1]);
        $this->assertSame('-5', $row[2], 'A negative number is not mistaken for a formula.');
        $this->assertSame('-0.25', $row[3]);
        $this->assertSame('00123', $row[5]);
    }

    public function test_null_empty_and_missing_values_are_empty_cells(): void
    {
        OrderResource::$extra = ['nothing' => null, 'blank' => '', 'flag' => null];
        $this->seedOrders(1, ['document_date' => null, 'partner_id' => null]);

        $this->export(['format' => 'csv', 'columns' => ['nothing' => 'A', 'blank' => 'B', 'flag' => 'C', 'document_date' => 'D', 'number' => 'N']]);

        $this->assertSame(['', '', '', '', 'ORD-00001'], $this->csv()[1]);

        $this->finished = [];
        self::$files = [];
        $this->export(['format' => 'excel', 'columns' => ['nothing' => 'A', 'document_date' => 'D', 'number' => 'N']]);
        $sheet = $this->sheet();

        $this->assertSame('', (string) $sheet->getCell('A2')->getValue());
        $this->assertSame('ORD-00001', (string) $sheet->getCell('C2')->getValue());
    }

    public function test_characters_that_break_xml_do_not_corrupt_the_xlsx(): void
    {
        $nasty = "a\x01b\x08c\x0Bd\x0Ce\x1Ff <tag> & \"quote\" 'apos' 😀 ёж Ω \u{FFFE} end";
        $this->seedOrders(1, ['comment' => $nasty]);

        $this->export(['format' => 'excel', 'columns' => ['comment' => 'Комментарий <&>']]);
        $sheet = $this->sheet();   // PhpSpreadsheet fails to load a corrupt file.

        $this->assertSame('Комментарий <&>', (string) $sheet->getCell('A1')->getValue());
        $value = (string) $sheet->getCell('A2')->getValue();
        $this->assertStringContainsString('<tag> & "quote" \'apos\' 😀 ёж Ω', $value);
        $this->assertStringContainsString('end', $value);
    }

    public function test_a_very_long_text_stays_within_the_excel_cell_limit(): void
    {
        $this->seedOrders(1, ['comment' => str_repeat('я', 40000)]);

        $this->export(['format' => 'excel', 'columns' => ['comment' => 'C']]);
        $value = (string) $this->sheet()->getCell('A2')->getValue();

        $this->assertLessThanOrEqual(32767, mb_strlen($value), 'Excel refuses a cell over 32767 characters.');
    }

    public function test_sibling_lists_and_five_levels_of_nesting(): void
    {
        OrderResource::$extra = [
            'l1' => [['a' => 'A1', 'l2' => [['b' => 'B1', 'l3' => [['c' => 'C1', 'l4' => [['d' => 'D1', 'l5' => [['e' => 'E1']]]]]]]]]],
            'other' => [['x' => 'X1'], ['x' => 'X2']],
        ];
        $this->seedOrders(2, [], lines: 1);

        $this->export(['format' => 'csv', 'columns' => [
            'number' => 'No',
            'lines' => ['sequence' => '#'],
            'other' => ['x' => 'X'],
            'l1' => ['a' => 'A', 'l2' => ['b' => 'B', 'l3' => ['c' => 'C', 'l4' => ['d' => 'D', 'l5' => ['e' => 'E']]]]],
        ]]);

        $flat = array_map(fn (array $r): string => implode('|', $r), $this->csv());

        $this->assertContains('ORD-00001', $flat);
        $this->assertContains('|1', $flat);
        $this->assertContains('|X1', $flat);
        $this->assertContains('|X2', $flat);
        $this->assertContains('|||||E1', $flat, 'The fifth level is shifted five columns.');
        $this->assertSame(2, count(array_keys($flat, 'ORD-00001')) + count(array_keys($flat, 'ORD-00002')));
    }

    public function test_an_item_missing_a_requested_column_gets_an_empty_cell(): void
    {
        OrderResource::$extra = ['other' => [['x' => 'X1', 'y' => 'Y1'], ['x' => 'X2']]];
        $this->seedOrders(1);

        $this->export(['format' => 'csv', 'columns' => ['number' => 'No', 'other' => ['x' => 'X', 'y' => 'Y']]]);

        $this->assertContains(['', 'X2', ''], $this->csv());
    }

    public function test_a_column_only_some_rows_yield_is_found_beyond_the_first_sample(): void
    {
        $this->seedOrders(120);
        OrderResource::$extra = [];
        // The comment is set on one late row only; the resource yields `rare` just for it.
        $late = Order::query()->where('number', 'ORD-00110')->first();
        $late->update(['comment' => 'tagged']);

        $this->export(['format' => 'csv', 'columns' => ['number' => 'No', 'tags' => 'Tags']]);

        $this->assertCount(121, $this->csv());
        $this->assertSame('a, b', $this->csv()[110][1]);
    }

    public function test_a_hundred_thousand_rows_stay_in_bounded_memory(): void
    {
        if (! getenv('EXPORT_TEST_BIG')) {
            $this->markTestSkipped('Set EXPORT_TEST_BIG=1 for the 100k-row run.');
        }

        DB::statement("insert into e2e_partners (name) values ('Big')");
        DB::statement("insert into e2e_orders (number, partner_id, document_date, total_amount, is_paid, created_at, updated_at)
            select 'ORD-' || lpad(g::text, 6, '0'), 1, date '2026-01-01' + (g % 300), g * 1.5, g % 2 = 0, now(), now() from generate_series(1, 100000) g");
        config(['export.chunk_size' => 500]);

        foreach (['csv', 'excel'] as $format) {
            $this->finished = [];
            self::$files = [];
            $start = microtime(true);
            $this->export(['format' => $format, 'columns' => ['number' => 'No', 'document_date' => 'Date', 'total_amount' => 'Total', 'is_paid' => 'Paid', 'partner.name' => 'Partner']]);
            $this->assertTrue($this->finished[0]->succeeded(), (string) $this->finished[0]->errorCode);
            $this->assertSame(100000, $this->finished[0]->rows);
            fwrite(STDERR, sprintf("\n[%s] 100k rows: %.1fs, peak %.0f MB, %.1f MB file\n", $format, microtime(true) - $start, memory_get_peak_usage(true) / 1048576, strlen(end(self::$files)) / 1048576));
            $this->assertLessThan(256 * 1048576, memory_get_peak_usage(true));
        }
    }

    public function test_documents_with_nested_lists_at_scale(): void
    {
        if (! getenv('EXPORT_TEST_BIG')) {
            $this->markTestSkipped('Set EXPORT_TEST_BIG=1 for the nested-list run.');
        }

        DB::statement("insert into e2e_partners (name) values ('Big')");
        DB::statement("insert into e2e_orders (number, partner_id, document_date, total_amount, is_paid, created_at, updated_at)
            select 'ORD-' || lpad(g::text, 6, '0'), 1, date '2026-01-01', g, false, now(), now() from generate_series(1, 5000) g");
        DB::statement("insert into e2e_lines (order_id, sequence, product_name, quantity, price)
            select o.id, s, 'P' || s, s, s * 2.5 from e2e_orders o, generate_series(1, 5) s");
        DB::statement("insert into e2e_serials (line_id, imei) select l.id, 'IMEI-' || l.id || '-' || s from e2e_lines l, generate_series(1, 2) s");

        foreach (['csv', 'excel'] as $format) {
            $this->finished = [];
            self::$files = [];
            $start = microtime(true);
            $this->export(['format' => $format, 'columns' => ['number' => 'No', 'lines' => ['sequence' => '#', 'product.name' => 'P', 'serials' => ['imei' => 'IMEI']]]]);
            $this->assertTrue($this->finished[0]->succeeded(), (string) $this->finished[0]->errorCode);
            // 5000 documents + per document: 1 + 5 lines + 5 * (1 + 2) = 21 sub rows.
            $this->assertSame(5000 * 22, $this->finished[0]->rows);
            fwrite(STDERR, sprintf("\n[%s] 5k documents, %d rows: %.1fs, peak %.0f MB\n", $format, $this->finished[0]->rows, microtime(true) - $start, memory_get_peak_usage(true) / 1048576));
        }
    }

    public function test_a_type_declared_by_the_filters_field_wins_over_the_guess(): void
    {
        OrderResource::$extra = ['code' => '42', 'uuid' => '0b9f1c3e-0000-4000-8000-000000000001', 'flag' => 1, 'ean' => '00123'];
        OrderFilter::$declared = [
            'code' => FieldType::Integer,
            'uuid' => FieldType::Uuid,
            'flag' => FieldType::Boolean,
            'ean' => FieldType::String,
        ];
        $this->seedOrders(1);

        $this->export(['format' => 'excel', 'columns' => ['code' => 'Code', 'uuid' => 'Uuid', 'flag' => 'Flag', 'ean' => 'Ean']]);
        $sheet = $this->sheet();

        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('A2')->getDataType(), 'A numeric string in a column declared Integer is a number.');
        $this->assertSame(42, $sheet->getCell('A2')->getValue());
        $this->assertSame('0b9f1c3e-0000-4000-8000-000000000001', (string) $sheet->getCell('B2')->getValue());
        $this->assertSame('Yes', (string) $sheet->getCell('C2')->getValue(), '1 in a column declared Boolean is the true text.');
        $this->assertSame('00123', (string) $sheet->getCell('D2')->getValue());
    }
}
