<?php

declare(strict_types=1);

namespace Alif\Export\Helpers\Writers;

use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportStyle;

final class CsvWriter implements Writer
{
    private const array FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public function __construct(private readonly ExportStyle $style = new ExportStyle) {}

    public function write(string $title, array $headings, array $numeric, iterable $rows, string $path): int
    {
        $file = @fopen($path, 'wb');

        if ($file === false) {
            throw ExportException::storageWriteFailed("cannot open $path");
        }

        $delimiter = $this->style->csvDelimiter();
        $enclosure = $this->style->csvEnclosure();
        $lineEnding = $this->style->csvLineEnding();

        try {
            if ($this->style->csvBom()) {
                fwrite($file, "\xEF\xBB\xBF");
            }

            fputcsv($file, $headings, $delimiter, $enclosure, '', $lineEnding);

            $count = 0;
            foreach ($rows as $row) {
                fputcsv($file, $this->guard($row, $numeric), $delimiter, $enclosure, '', $lineEnding);
                $count++;
            }

            return $count;
        } finally {
            fclose($file);
        }
    }

    /**
     * @param  list<string|int|float|null>  $row
     * @param  list<bool>  $numeric
     * @return list<string|int|float|null>
     */
    private function guard(array $row, array $numeric): array
    {
        $out = [];

        foreach ($row as $i => $cell) {
            // Exempt from formula neutralising only when the value really is a number, not by column flag alone.
            $isNumber = ($numeric[$i] ?? false) && is_numeric($cell);
            $out[] = ! $isNumber && is_string($cell) && $cell !== '' && in_array($cell[0], self::FORMULA_PREFIXES, true)
                ? "'".$cell
                : $cell;
        }

        return $out;
    }
}
