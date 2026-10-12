<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Unit;

use Alif\Export\Enums\ExportFormat;
use Alif\Export\Tests\TestCaseWithoutDatabase;

final class ExportFormatTest extends TestCaseWithoutDatabase
{
    public function test_only_csv_and_excel_exist(): void
    {
        $this->assertSame(['csv', 'excel'], array_map(fn (ExportFormat $f): string => $f->value, ExportFormat::cases()));
        $this->assertNull(ExportFormat::tryFrom('pdf'));
        $this->assertNull(ExportFormat::tryFrom('CSV'));
    }

    public function test_excel_is_written_with_the_xlsx_extension(): void
    {
        $this->assertNull(ExportFormat::tryFrom('xlsx'));
        $this->assertSame('csv', ExportFormat::CSV->extension());
        $this->assertSame('xlsx', ExportFormat::EXCEL->extension());
    }

    public function test_mime_types(): void
    {
        $this->assertSame('text/csv', ExportFormat::CSV->mimeType());
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ExportFormat::EXCEL->mimeType());
    }

    public function test_max_rows_reads_the_per_format_config(): void
    {
        $this->assertSame(500_000, ExportFormat::CSV->maxRows());

        config(['export.max_rows.csv' => 7, 'export.max_rows.xlsx' => 3]);

        $this->assertSame(7, ExportFormat::CSV->maxRows());
        $this->assertSame(3, ExportFormat::EXCEL->maxRows());
    }
}
