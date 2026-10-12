<?php

declare(strict_types=1);

namespace Alif\Export\Enums;

use Alif\Export\Helpers\ExportConfig;
use Alif\Export\Helpers\Writers\CsvWriter;
use Alif\Export\Helpers\Writers\Writer;
use Alif\Export\Helpers\Writers\XlsxWriter;

enum ExportFormat: string
{
    case CSV = 'csv';
    case EXCEL = 'excel';

    public function extension(): string
    {
        return match ($this) {
            self::CSV => 'csv',
            self::EXCEL => 'xlsx',
        };
    }

    public function maxRows(): int
    {
        return ExportConfig::int("max_rows.{$this->extension()}");
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::CSV => 'text/csv',
            self::EXCEL => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }

    public function writer(): Writer
    {
        return match ($this) {
            self::CSV => new CsvWriter,
            self::EXCEL => new XlsxWriter,
        };
    }
}
