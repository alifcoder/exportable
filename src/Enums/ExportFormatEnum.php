<?php

declare(strict_types=1);

namespace Alif\Export\Enums;

use Alif\Export\Helpers\ExportConfig;
use Alif\Export\Helpers\Writers\CsvWriter;
use Alif\Export\Helpers\Writers\Writer;
use Alif\Export\Helpers\Writers\XlsxWriter;

/** The file formats an export can be written in. */
enum ExportFormatEnum: string
{
    case CSV = 'csv';
    case EXCEL = 'excel';

    /** The file extension of this format. */
    public function extension(): string
    {
        return match ($this) {
            self::CSV => 'csv',
            self::EXCEL => 'xlsx',
        };
    }

    /** The most rows this format may have, from the config. */
    public function maxRows(): int
    {
        return ExportConfig::int("max_rows.{$this->extension()}");
    }

    /** The MIME type of this format. */
    public function mimeType(): string
    {
        return match ($this) {
            self::CSV => 'text/csv',
            self::EXCEL => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }

    /** The writer class that creates the file of this format. */
    public function writer(): Writer
    {
        return match ($this) {
            self::CSV => new CsvWriter,
            self::EXCEL => new XlsxWriter,
        };
    }
}
