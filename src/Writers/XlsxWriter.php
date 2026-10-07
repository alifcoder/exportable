<?php

declare(strict_types=1);

namespace Alif\Export\Writers;

use Alif\Export\ExportException;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as SpoutWriter;

/** Streams rows to disk, so memory stays flat. Text is always a StringCell: a leading "=" is never a formula. */
final class XlsxWriter implements Writer
{
    /** Excel keeps about 15 significant digits; longer numbers are written as exact text. */
    private const int MAX_SAFE_DIGITS = 15;

    public function write(string $title, array $headings, array $numeric, iterable $rows, string $disk, string $path): int
    {
        $temp = tempnam(sys_get_temp_dir(), 'export_xlsx_');
        $writer = new SpoutWriter;
        $writer->openToFile($temp);

        try {
            $writer->addRow(new Row(array_map(fn (string $heading): StringCell => new StringCell($heading, null), $headings)));

            $count = 0;
            foreach ($rows as $row) {
                $writer->addRow(new Row($this->cells($row, $numeric)));
                $count++;
            }

            $writer->close();

            $stream = fopen($temp, 'rb');

            if ($stream === false) {
                throw ExportException::storageWriteFailed($disk, $path);
            }

            try {
                if (! Storage::disk($disk)->writeStream($path, $stream)) {
                    throw ExportException::storageWriteFailed($disk, $path);
                }
            } finally {
                fclose($stream);
            }

            return $count;
        } finally {
            @unlink($temp);
        }
    }

    /**
     * @param  list<string|int|float|null>  $row
     * @param  list<bool>  $numeric
     * @return list<NumericCell|StringCell>
     */
    private function cells(array $row, array $numeric): array
    {
        $cells = [];

        foreach ($row as $i => $value) {
            $number = ($numeric[$i] ?? false) ? $this->asNumber($value) : null;
            $cells[] = $number !== null ? new NumericCell($number, null) : new StringCell((string) $value, null);
        }

        return $cells;
    }

    private function asNumber(string|int|float|null $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $this->digits((string) $value) <= self::MAX_SAFE_DIGITS ? $value : null;
        }

        return is_string($value) && is_numeric($value) && $this->digits($value) <= self::MAX_SAFE_DIGITS ? $value + 0 : null;
    }

    private function digits(string $number): int
    {
        return strlen(ltrim(str_replace(['-', '+', '.'], '', $number), '0'));
    }
}
