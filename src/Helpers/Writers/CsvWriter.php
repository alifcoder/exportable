<?php

declare(strict_types=1);

namespace Alif\Export\Helpers\Writers;

use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportStyle;
use Alif\QueryFilter\Enums\FieldType;
use Carbon\CarbonInterface;

/** Writes the export as a csv file, line by line. */
final class CsvWriter implements Writer
{
    private const array FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public function __construct(private readonly ExportStyle $style = new ExportStyle) {}

    /** Writes the headings, then every line, to the csv file and returns how many lines were written. */
    public function write(array $headings, array $types, iterable $lines, string $path, array $widths = []): int
    {
        $file = @fopen($path, 'wb');

        if ($file === false) {
            throw ExportException::storageWriteFailed(__('export::messages.storage.cannot_open', ['path' => $path]));
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
            foreach ($lines as $line) {
                // A sub row sits `depth` columns to the right of its document row, under it.
                $row = [...array_fill(0, $line->depth, ''), ...$this->cells($line->cells, $line->heading ? [] : $line->types)];
                fputcsv($file, $row, $delimiter, $enclosure, '', $lineEnding);
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
     * @param  list<FieldType|null>  $types
     * @return list<string|int|float>
     */
    private function cells(array $row, array $types): array
    {
        $out = [];

        foreach ($row as $i => $value) {
            $cell = TypedValue::of($types[$i] ?? null, $value);

            $out[] = match ($cell->type) {
                FieldType::Integer, FieldType::Decimal => $cell->raw,
                FieldType::Boolean => $this->style->booleanLabel($cell->value),
                FieldType::Date => $cell->value->format($this->style->dateFormat()),
                FieldType::DateTime => $cell->value->format($this->style->dateTimeFormat()),
                FieldType::Time => $cell->value->format($this->style->timeFormat()),
                default => $this->neutralised($cell->value),
            };
        }

        return $out;
    }

    /** Puts a `'` before text that starts like a formula, so a spreadsheet does not run it. */
    private function neutralised(string $text): string
    {
        return $text !== '' && in_array($text[0], self::FORMULA_PREFIXES, true) ? "'".$text : $text;
    }
}
