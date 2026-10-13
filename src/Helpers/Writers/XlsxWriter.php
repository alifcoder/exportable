<?php

declare(strict_types=1);

namespace Alif\Export\Helpers\Writers;

use Alif\Export\Enums\ColumnTypeEnum;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportStyle;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Common\Exception\IOException;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer as SpoutWriter;

/**
 * Streams rows to a local file, so memory stays flat. Text is always a StringCell: a leading "=" is never a
 * formula. Dates are real Excel dates. Fonts, header look, number and date formats and column widths come from
 * `export.style`.
 */
final class XlsxWriter implements Writer
{
    public function __construct(private readonly ExportStyle $style = new ExportStyle) {}

    /** Writes the headings and all lines to the xlsx file, then lets `XlsxPackage` finish it. */
    public function write(array $headings, array $types, iterable $lines, string $path, array $widths = []): int
    {
        $options = new Options;
        // Longest text per column, sub rows included: it sizes the columns once every row is known.
        $longest = $widths !== [] ? $widths : array_map(mb_strlen(...), $headings);

        $writer = new SpoutWriter($options);
        try {
            $writer->openToFile($path);
        } catch (IOException $e) {
            throw ExportException::storageWriteFailed($e->getMessage());
        }

        try {
            $header = $this->headerStyle();
            $writer->addRow(new Row(array_map(fn (string $heading): StringCell => new StringCell($heading, $header), $headings)));

            $styles = $this->cellStyles();

            // One byte per written row, the heading row first: how deep it sits, for the outline below.
            $depths = "\0";
            $count = 0;
            foreach ($lines as $line) {
                $cells = $line->heading
                    ? $this->headings($line, $header, $longest)
                    : $this->cells($line, $styles, $longest);
                $writer->addRow(new Row([...array_fill(0, $line->depth, new StringCell('', null)), ...$cells]));
                $depths .= chr(min(255, $line->depth));
                $count++;
            }
        } finally {
            $writer->close();
        }

        (new XlsxPackage)->finish($path, $depths, $this->columnWidths($longest));

        return $count;
    }

    /** The default style of a cell: the configured font, centered. */
    private function baseStyle(): Style
    {
        return $this->centered((new Style)->setFontName($this->style->fontName())->setFontSize($this->style->fontSize()));
    }

    /** Centers the cell, both across and up and down. */
    private function centered(Style $style): Style
    {
        return $style->setCellAlignment(CellAlignment::CENTER)->setCellVerticalAlignment(CellVerticalAlignment::CENTER);
    }

    /**
     * Column widths in characters: the longest text plus padding, clamped to min..max.
     *
     * @param  array<int, int>  $longest
     * @return array<int, float>
     */
    private function columnWidths(array $longest): array
    {
        return array_map(
            fn (int $length): float => min($this->style->maxColumnWidth(), max($this->style->minColumnWidth(), $length + $this->style->columnPadding())),
            $longest,
        );
    }

    /**
     * Makes the cells of a heading line (the labels of a list) and remembers their widths.
     *
     * @param  array<int, int>  $longest
     * @return list<StringCell>
     */
    private function headings(Line $line, Style $style, array &$longest): array
    {
        $cells = [];

        foreach ($line->cells as $i => $heading) {
            $text = (string) $heading;
            $at = $line->depth + $i;
            $longest[$at] = max($longest[$at] ?? 0, mb_strlen($text));
            $cells[] = new StringCell($text, $style);
        }

        return $cells;
    }

    /** The style of the header row: font, size, color, background and bold from the config. */
    private function headerStyle(): Style
    {
        $style = $this->centered((new Style)
            ->setFontName($this->style->fontName())
            ->setFontSize($this->style->headerFontSize())
            ->setFontColor($this->rgb($this->style->headerFontColor()))
            ->setBackgroundColor($this->style->headerBackground()));

        return $this->style->headerBold() ? $style->setFontBold() : $style;
    }

    /** The style config holds ARGB; the spreadsheet library adds the alpha itself, so it takes the RGB part. */
    private function rgb(string $argb): string
    {
        return substr($argb, -6);
    }

    /** @return array<string, Style> A style per cell type, built once per file. */
    private function cellStyles(): array
    {
        $format = fn (string $format): Style => (clone $this->baseStyle())->setFormat($format);

        return [
            ColumnTypeEnum::String->value => $this->baseStyle(),
            ColumnTypeEnum::Integer->value => $format($this->style->integerFormat()),
            ColumnTypeEnum::Decimal->value => $format($this->style->numberFormat()),
            ColumnTypeEnum::Percent->value => $format($this->style->percentFormat()),
            ColumnTypeEnum::Currency->value => $format($this->style->currencyFormat()),
            ColumnTypeEnum::Date->value => $format($this->style->dateCellFormat()),
            ColumnTypeEnum::DateTime->value => $format($this->style->dateTimeCellFormat()),
            ColumnTypeEnum::Time->value => $format($this->style->timeCellFormat()),
        ];
    }

    /**
     * @param  array<string, Style>  $styles
     * @param  array<int, int>  $longest
     * @return list<NumericCell|StringCell|DateTimeCell>
     */
    private function cells(Line $line, array $styles, array &$longest): array
    {
        $cells = [];

        foreach ($line->cells as $i => $value) {
            $cell = TypedValue::of($line->types[$i] ?? null, $value);
            $style = $styles[$cell->type->value] ?? $styles[ColumnTypeEnum::String->value];

            $cells[] = match ($cell->type) {
                ColumnTypeEnum::Integer, ColumnTypeEnum::Decimal, ColumnTypeEnum::Percent, ColumnTypeEnum::Currency => new NumericCell($cell->value, $style),
                ColumnTypeEnum::Date, ColumnTypeEnum::DateTime, ColumnTypeEnum::Time => new DateTimeCell($cell->value->toDateTimeImmutable(), $style),
                ColumnTypeEnum::Boolean => new StringCell($this->style->booleanLabel($cell->value), $style),
                default => new StringCell($cell->value, $style),
            };

            $at = $line->depth + $i;
            $longest[$at] = max($longest[$at] ?? 0, $this->length($cell));
        }

        return $cells;
    }

    /** How many characters the cell shows: the text, a number with its separators and decimals, a date by its format. */
    private function length(TypedValue $cell): int
    {
        return match ($cell->type) {
            ColumnTypeEnum::Integer => strlen(number_format((float) $cell->value, 0)),
            ColumnTypeEnum::Decimal, ColumnTypeEnum::Currency => strlen(number_format((float) $cell->value, 2)),
            ColumnTypeEnum::Percent => strlen(number_format((float) $cell->value, 2)) + 1,
            ColumnTypeEnum::Date => strlen($this->style->dateCellFormat()),
            ColumnTypeEnum::DateTime => strlen($this->style->dateTimeCellFormat()),
            ColumnTypeEnum::Time => strlen($this->style->timeCellFormat()),
            ColumnTypeEnum::Boolean => mb_strlen($this->style->booleanLabel($cell->value)),
            default => mb_strlen((string) $cell->value),
        };
    }
}
