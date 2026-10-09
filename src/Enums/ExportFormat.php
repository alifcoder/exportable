<?php

declare(strict_types=1);

namespace Alif\Export\Enums;

use Alif\Export\Helpers\ExportConfig;

enum ExportFormat: string
{
    case CSV = 'csv';
    case XLSX = 'xlsx';

    public function extension(): string
    {
        return $this->value;
    }

    public function maxRows(): int
    {
        return ExportConfig::int("max_rows.{$this->value}");
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::CSV => 'text/csv',
            self::XLSX => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }
}
