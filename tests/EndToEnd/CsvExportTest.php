<?php

declare(strict_types=1);

namespace Alif\Export\Tests\EndToEnd;

final class CsvExportTest extends EndToEndTestCase
{
    /** @return list<list<string|null>> */
    private function parse(string $csv): array
    {
        $rows = [];
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, ltrim($csv, "\xEF\xBB\xBF"));
        rewind($stream);

        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function test_header_values_bom_and_types(): void
    {
        $this->seedOrders(2);

        $this->export(['format' => 'csv', 'columns' => [
            'number' => 'No', 'document_date' => 'Date', 'created_at' => 'Created', 'total_amount' => 'Total', 'is_paid' => 'Paid', 'partner.name' => 'Partner',
        ]]);

        $csv = $this->lastFile();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertSame([
            ['No', 'Date', 'Created', 'Total', 'Paid', 'Partner'],
            ['ORD-00001', '2026-03-04', '2026-03-04 05:06:07', '1.50', 'No', 'Acme'],
            ['ORD-00002', '2026-03-04', '2026-03-04 05:06:07', '3.00', 'Yes', 'Acme'],
        ], $this->parse($csv));
    }

    public function test_columns_keep_the_order_the_client_sent(): void
    {
        $this->seedOrders(1);

        $this->export(['format' => 'csv', 'columns' => ['partner.name' => 'P', 'number' => 'N', 'total_amount' => 'T']]);

        $this->assertSame(['P', 'N', 'T'], $this->parse($this->lastFile())[0]);
    }

    public function test_dates_are_shown_in_the_app_timezone(): void
    {
        config(['app.timezone' => 'Asia/Tashkent']);
        $this->seedOrders(1);

        $this->export(['format' => 'csv', 'columns' => ['created_at' => 'Created']]);

        $this->assertSame('2026-03-04 10:06:07', $this->parse($this->lastFile())[1][0]);
    }

    public function test_formula_prefixes_are_neutralised_but_numbers_are_not(): void
    {
        foreach (['=SUM(1)', '+1', '-1x', '@cmd'] as $i => $comment) {
            $this->seedOrders(1, ['comment' => $comment, 'number' => 'F'.$i]);
        }

        $this->export(['format' => 'csv', 'columns' => ['comment' => 'C', 'total_amount' => 'T']]);

        $rows = $this->parse($this->lastFile());

        foreach (array_slice($rows, 1) as $row) {
            $this->assertDoesNotMatchRegularExpression('/^[=+\-@]/', (string) $row[0], 'A text cell must not start a formula.');
            $this->assertSame('1.50', $row[1]);
        }
    }

    public function test_quotes_commas_newlines_and_unicode_survive(): void
    {
        $this->seedOrders(1, ['comment' => "Қоро \"A\", B\nC — ёж"]);

        $this->export(['format' => 'csv', 'columns' => ['comment' => 'Comment']]);

        $this->assertSame("Қоро \"A\", B\nC — ёж", $this->parse($this->lastFile())[1][0]);
    }

    public function test_a_list_of_scalars_is_one_joined_cell(): void
    {
        $this->seedOrders(1, ['comment' => 'tagged']);

        $this->export(['format' => 'csv', 'columns' => ['tags' => 'Tags']]);

        $this->assertSame('a, b', $this->parse($this->lastFile())[1][0]);
    }

    public function test_lists_are_sub_rows_shifted_by_their_depth(): void
    {
        $this->seedOrders(1, [], lines: 2, serials: 1);

        $this->export(['format' => 'csv', 'columns' => [
            'number' => 'No',
            'lines' => ['sequence' => '#', 'product.name' => 'Product', 'serials' => ['imei' => 'IMEI']],
        ]]);

        $this->assertSame([
            ['No'],
            ['ORD-00001'],
            ['', '#', 'Product'],
            ['', '1', 'P1'],
            ['', '', 'IMEI'],
            ['', '', 'IMEI-1-1-1'],
            ['', '2', 'P2'],
            ['', '', 'IMEI'],
            ['', '', 'IMEI-1-2-1'],
        ], $this->parse($this->lastFile()));
    }

    public function test_a_document_without_items_writes_no_sub_rows(): void
    {
        $this->seedOrders(2);

        $this->export(['format' => 'csv', 'columns' => ['number' => 'No', 'lines' => ['sequence' => '#']]]);

        $this->assertSame([['No'], ['ORD-00001'], ['ORD-00002']], $this->parse($this->lastFile()));
    }

    public function test_the_csv_layout_follows_the_style_config(): void
    {
        config(['export.style.csv' => ['delimiter' => ';', 'enclosure' => "'", 'line_ending' => "\r\n", 'bom' => false]]);
        $this->seedOrders(1, ['comment' => 'a;b']);

        $this->export(['format' => 'csv', 'columns' => ['number' => 'No', 'comment' => 'C']]);

        $this->assertSame("No;C\r\nORD-00001;'a;b'\r\n", $this->lastFile());
    }

    public function test_boolean_text_comes_from_the_config_and_is_translated(): void
    {
        config(['export.style.boolean' => ['true' => 'export::messages.no_data', 'false' => 'Off']]);
        $this->seedOrders(2);

        $this->export(['format' => 'csv', 'columns' => ['is_paid' => 'Paid']]);

        $this->assertSame([['Paid'], ['Off'], ['There is no data to export.']], $this->parse($this->lastFile()));
    }

    public function test_the_stored_file_is_named_after_the_task_and_has_the_csv_mime_type(): void
    {
        $this->seedOrders(1);

        $task = $this->export(['format' => 'csv', 'columns' => ['number' => 'No']]);

        $this->assertSame("{$task->id}|{$task->id}.csv|text/csv", array_key_first(self::$files));
    }
}
