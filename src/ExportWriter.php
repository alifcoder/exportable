<?php

declare(strict_types=1);

namespace Alif\Export;

use Alif\Export\Enums\ExportFormat;
use Alif\Export\Writers\CsvWriter;
use Alif\Export\Writers\PdfWriter;
use Alif\Export\Writers\Writer;
use Alif\Export\Writers\XlsxWriter;

final class ExportWriter
{
    /**
     * @param  list<string>  $headings
     * @param  list<bool>  $numeric
     * @param  iterable<int, list<string|int|float|null>>  $rows
     * @return int Rows written (excluding the heading row).
     */
    public function store(
        ExportFormat $format,
        string $title,
        array $headings,
        array $numeric,
        iterable $rows,
        string $disk,
        string $path,
    ): int {
        return $this->writer($format)->write($title, $headings, $numeric, $rows, $disk, $path);
    }

    private function writer(ExportFormat $format): Writer
    {
        return match ($format) {
            ExportFormat::Csv => new CsvWriter,
            ExportFormat::Xlsx => new XlsxWriter,
            ExportFormat::Pdf => new PdfWriter,
        };
    }
}
