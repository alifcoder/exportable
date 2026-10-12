<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Unit;

use Alif\Export\Enums\ColumnType;
use Alif\Export\Helpers\Column;
use Alif\Export\Helpers\Writers\CsvWriter;
use Alif\Export\Helpers\Writers\XlsxWriter;
use Alif\Export\Tests\TestCaseWithoutDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class ColumnTypesTest extends TestCaseWithoutDatabase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * @param  list<ColumnType|null>  $types
     * @param  list<mixed>  $row
     */
    private function sheet(array $types, array $row): Worksheet
    {
        (new XlsxWriter)->write('t', array_map(fn (int $i): string => "h$i", array_keys($types)), $types, [$row], Storage::disk('local')->path('o.xlsx'));

        return IOFactory::load(Storage::disk('local')->path('o.xlsx'))->getActiveSheet();
    }

    /**
     * @param  list<ColumnType|null>  $types
     * @param  list<mixed>  $row
     */
    private function csvRow(array $types, array $row): string
    {
        (new CsvWriter)->write('t', array_map(fn (int $i): string => "h$i", array_keys($types)), $types, [$row], Storage::disk('local')->path('o.csv'));

        return explode("\n", substr(Storage::disk('local')->get('o.csv'), 3))[1];
    }

    public function test_column_without_a_type_reads_the_type_of_each_value(): void
    {
        $sheet = $this->sheet([null, null, null, null, null], [7, 1.5, true, Carbon::parse('2026-03-04 05:06:07'), 'text']);

        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('A2')->getDataType());
        $this->assertSame('#,##0', $sheet->getStyle('A2')->getNumberFormat()->getFormatCode());
        $this->assertSame('#,##0.00', $sheet->getStyle('B2')->getNumberFormat()->getFormatCode());
        $this->assertSame('Yes', (string) $sheet->getCell('C2')->getValue());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('D2')->getDataType());
        $this->assertSame('text', (string) $sheet->getCell('E2')->getValue());
    }

    public function test_xlsx_dates_are_real_excel_dates_with_the_configured_format(): void
    {
        config(['app.timezone' => 'UTC']);
        $sheet = $this->sheet([ColumnType::Date, ColumnType::DateTime, ColumnType::Time], ['2026-03-04', '2026-03-04T05:06:07.000000Z', '2026-03-04 05:06:07']);

        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('A2')->getDataType());
        $this->assertSame('yyyy-mm-dd', $sheet->getStyle('A2')->getNumberFormat()->getFormatCode());
        $this->assertEqualsWithDelta(Carbon::parse('2026-03-04 05:06:07', 'UTC')->timestamp, Date::excelToTimestamp($sheet->getCell('B2')->getValue(), 'UTC'), 1);
        $this->assertSame('yyyy-mm-dd hh:mm:ss', $sheet->getStyle('B2')->getNumberFormat()->getFormatCode());
        $this->assertSame('hh:mm:ss', $sheet->getStyle('C2')->getNumberFormat()->getFormatCode());
    }

    public function test_a_date_in_the_app_timezone_is_converted_from_utc(): void
    {
        config(['app.timezone' => 'Asia/Tashkent']);
        $sheet = $this->sheet([ColumnType::DateTime], ['2026-03-04T00:00:00.000000Z']);

        $this->assertEqualsWithDelta(Carbon::parse('2026-03-04 05:00:00', 'UTC')->timestamp, Date::excelToTimestamp($sheet->getCell('A2')->getValue(), 'UTC'), 1);
    }

    public function test_percent_and_currency_use_their_own_formats(): void
    {
        config(['export.style.xlsx.percent_format' => '0.0"%"', 'export.style.xlsx.currency_format' => '#,##0 "UZS"']);
        $sheet = $this->sheet([ColumnType::Percent, ColumnType::Currency, ColumnType::Integer, ColumnType::Decimal], ['25', '1500000.5', '3.0', 2]);

        $this->assertSame(25, $sheet->getCell('A2')->getValue());
        $this->assertSame('0.0"%"', $sheet->getStyle('A2')->getNumberFormat()->getFormatCode());
        $this->assertSame('#,##0 "UZS"', $sheet->getStyle('B2')->getNumberFormat()->getFormatCode());
        $this->assertSame('#,##0', $sheet->getStyle('C2')->getNumberFormat()->getFormatCode());
        $this->assertSame('#,##0.00', $sheet->getStyle('D2')->getNumberFormat()->getFormatCode());
    }

    public function test_boolean_text_comes_from_the_config_and_accepts_common_flags(): void
    {
        config(['export.style.boolean' => ['true' => 'Ha', 'false' => "Yo'q"]]);
        $sheet = $this->sheet([ColumnType::Boolean, ColumnType::Boolean, ColumnType::Boolean, ColumnType::Boolean], [true, '0', 'true', 'maybe']);

        $this->assertSame(['Ha', "Yo'q", 'Ha', 'maybe'], array_map(fn (string $cell): string => (string) $sheet->getCell($cell)->getValue(), ['A2', 'B2', 'C2', 'D2']));
    }

    public function test_a_value_that_does_not_fit_its_type_is_kept_as_text(): void
    {
        $sheet = $this->sheet([ColumnType::Decimal, ColumnType::Date, ColumnType::Integer], ['n/a', 'not a date', '12345678901234567890']);

        $this->assertSame(['n/a', 'not a date', '12345678901234567890'], array_map(fn (string $cell): string => (string) $sheet->getCell($cell)->getValue(), ['A2', 'B2', 'C2']));
        $this->assertSame(DataType::TYPE_INLINE, $sheet->getCell('C2')->getDataType());
    }

    public function test_csv_writes_dates_booleans_and_numbers_as_configured(): void
    {
        config(['export.style.date_format' => 'd.m.Y', 'export.style.time_format' => 'H:i', 'export.style.boolean' => ['true' => 'Ha', 'false' => "Yo'q"]]);
        config(['app.timezone' => 'UTC']);

        $row = $this->csvRow(
            [ColumnType::Date, ColumnType::DateTime, ColumnType::Time, ColumnType::Boolean, ColumnType::Percent, ColumnType::Currency],
            ['2026-03-04', '2026-03-04T05:06:07.000000Z', '2026-03-04 05:06:07', false, '25.50', '1500.000'],
        );

        $this->assertSame('04.03.2026,"2026-03-04 05:06:07",05:06,Yo\'q,25.50,1500.000', $row);
    }

    public function test_column_api_sets_the_type(): void
    {
        $this->assertNull(Column::make('x')->type());
        $this->assertSame(ColumnType::Percent, Column::make('x')->as(ColumnType::Percent)->type());
        $this->assertSame(ColumnType::Date, Column::make('x')->date()->type());
        $this->assertSame(ColumnType::Number, Column::make('x')->numeric()->type());
        $this->assertTrue(Column::make('x')->as(ColumnType::Currency)->isNumeric());
        $this->assertFalse(Column::make('x')->as(ColumnType::Boolean)->isNumeric());
    }
}
