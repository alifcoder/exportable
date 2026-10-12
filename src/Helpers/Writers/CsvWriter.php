<?php

declare(strict_types=1);

namespace Alif\Export\Helpers\Writers;

use Alif\Export\Enums\ColumnType;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportStyle;
use Carbon\CarbonInterface;

final class CsvWriter implements Writer
{
    private const array FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public function __construct(private readonly ExportStyle $style = new ExportStyle) {}

    public function write(string $title, array $headings, array $types, iterable $rows, string $path): int
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
                fputcsv($file, $this->cells($row, $types), $delimiter, $enclosure, '', $lineEnding);
                $count++;
            }

            return $count;
        } finally {
            fclose($file);
        }
    }

    /**
     * Numbers are written as they are; dates and booleans as text by `export.style`. Text that a spreadsheet would
     * read as a formula is neutralised, a number never is.
     *
     * @param  list<string|int|float|bool|CarbonInterface|null>  $row
     * @param  list<ColumnType|null>  $types
     * @return list<string|int|float>
     */
    private function cells(array $row, array $types): array
    {
        $out = [];

        foreach ($row as $i => $value) {
            $cell = TypedValue::of($types[$i] ?? null, $value);

            $out[] = match ($cell->type) {
                ColumnType::Integer, ColumnType::Decimal, ColumnType::Percent, ColumnType::Currency => $cell->raw,
                ColumnType::Boolean => $this->style->booleanLabel($cell->value),
                ColumnType::Date => $cell->value->format($this->style->dateFormat()),
                ColumnType::DateTime => $cell->value->format($this->style->dateTimeFormat()),
                ColumnType::Time => $cell->value->format($this->style->timeFormat()),
                default => $this->neutralised($cell->value),
            };
        }

        return $out;
    }

    private function neutralised(string $text): string
    {
        return $text !== '' && in_array($text[0], self::FORMULA_PREFIXES, true) ? "'".$text : $text;
    }
}
