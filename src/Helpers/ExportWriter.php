<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Alif\Export\Enums\ColumnType;
use Alif\Export\Enums\ExportFormat;
use Alif\Export\Helpers\Writers\CsvWriter;
use Alif\Export\Helpers\Writers\Writer;
use Alif\Export\Helpers\Writers\XlsxWriter;
use Carbon\CarbonInterface;

final class ExportWriter
{
    /**
     * @param  list<string>  $headings
     * @param  list<ColumnType|null>  $types  Declared type per column; null reads the type of each value.
     * @param  iterable<int, list<string|int|float|bool|CarbonInterface|null>>  $rows
     * @param  string  $path  Local file to create.
     * @return int Rows written (excluding the heading row).
     */
    public function store(
        ExportFormat $format,
        array $headings,
        array $types,
        iterable $rows,
        string $path,
    ): int {
        return $this->writer($format)->write($headings, $types, $rows, $path);
    }

    private function writer(ExportFormat $format): Writer
    {
        return match ($format) {
            ExportFormat::CSV => new CsvWriter,
            ExportFormat::EXCEL => new XlsxWriter,
        };
    }
}
