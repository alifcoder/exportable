<?php

declare(strict_types=1);

namespace Alif\Export\Helpers\Writers;

use Alif\Export\Enums\ColumnType;
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

    private function baseStyle(): Style
    {
        return $this->centered((new Style)->setFontName($this->style->fontName())->setFontSize($this->style->fontSize()));
    }

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
            ColumnType::String->value => $this->baseStyle(),
            ColumnType::Integer->value => $format($this->style->integerFormat()),
            ColumnType::Decimal->value => $format($this->style->numberFormat()),
            ColumnType::Percent->value => $format($this->style->percentFormat()),
            ColumnType::Currency->value => $format($this->style->currencyFormat()),
            ColumnType::Date->value => $format($this->style->dateCellFormat()),
            ColumnType::DateTime->value => $format($this->style->dateTimeCellFormat()),
            ColumnType::Time->value => $format($this->style->timeCellFormat()),
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
            $style = $styles[$cell->type->value] ?? $styles[ColumnType::String->value];

            $cells[] = match ($cell->type) {
                ColumnType::Integer, ColumnType::Decimal, ColumnType::Percent, ColumnType::Currency => new NumericCell($cell->value, $style),
                ColumnType::Date, ColumnType::DateTime, ColumnType::Time => new DateTimeCell($cell->value->toDateTimeImmutable(), $style),
                ColumnType::Boolean => new StringCell($this->style->booleanLabel($cell->value), $style),
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
            ColumnType::Integer => strlen(number_format((float) $cell->value, 0)),
            ColumnType::Decimal, ColumnType::Currency => strlen(number_format((float) $cell->value, 2)),
            ColumnType::Percent => strlen(number_format((float) $cell->value, 2)) + 1,
            ColumnType::Date => strlen($this->style->dateCellFormat()),
            ColumnType::DateTime => strlen($this->style->dateTimeCellFormat()),
            ColumnType::Time => strlen($this->style->timeCellFormat()),
            ColumnType::Boolean => mb_strlen($this->style->booleanLabel($cell->value)),
            default => mb_strlen((string) $cell->value),
        };
    }
}
