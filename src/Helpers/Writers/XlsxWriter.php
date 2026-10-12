<?php

declare(strict_types=1);

namespace Alif\Export\Helpers\Writers;

use Alif\Export\Enums\ColumnType;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportStyle;
use Carbon\CarbonInterface;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
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

    public function write(string $title, array $headings, array $types, iterable $rows, string $path): int
    {
        $options = new Options;

        foreach ($headings as $i => $heading) {
            $width = min($this->style->maxColumnWidth(), max($this->style->minColumnWidth(), mb_strlen($heading) + $this->style->columnPadding()));
            $options->setColumnWidth($width, $i + 1);
        }

        $writer = new SpoutWriter($options);
        try {
            $writer->openToFile($path);
        } catch (IOException $e) {
            throw ExportException::storageWriteFailed($e->getMessage());
        }

        try {
            $writer->getCurrentSheet()->setName(mb_substr(trim(strtr($title, ['\\' => ' ', '/' => ' ', '?' => ' ', '*' => ' ', '[' => ' ', ']' => ' ', ':' => ' '])) ?: 'Export', 0, 31));

            $header = $this->headerStyle();
            $writer->addRow(new Row(array_map(fn (string $heading): StringCell => new StringCell($heading, $header), $headings)));

            $styles = $this->cellStyles();

            $count = 0;
            foreach ($rows as $row) {
                $writer->addRow(new Row($this->cells($row, $types, $styles)));
                $count++;
            }

            return $count;
        } finally {
            $writer->close();
        }
    }

    private function baseStyle(): Style
    {
        return (new Style)->setFontName($this->style->fontName())->setFontSize($this->style->fontSize());
    }

    private function headerStyle(): Style
    {
        $style = (new Style)
            ->setFontName($this->style->fontName())
            ->setFontSize($this->style->headerFontSize())
            ->setFontColor($this->style->headerFontColor())
            ->setBackgroundColor($this->style->headerBackground());

        return $this->style->headerBold() ? $style->setFontBold() : $style;
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
     * @param  list<string|int|float|bool|CarbonInterface|null>  $row
     * @param  list<ColumnType|null>  $types
     * @param  array<string, Style>  $styles
     * @return list<NumericCell|StringCell|DateTimeCell>
     */
    private function cells(array $row, array $types, array $styles): array
    {
        $cells = [];

        foreach ($row as $i => $value) {
            $cell = TypedValue::of($types[$i] ?? null, $value);
            $style = $styles[$cell->type->value] ?? $styles[ColumnType::String->value];

            $cells[] = match ($cell->type) {
                ColumnType::Integer, ColumnType::Decimal, ColumnType::Percent, ColumnType::Currency => new NumericCell($cell->value, $style),
                ColumnType::Date, ColumnType::DateTime, ColumnType::Time => new DateTimeCell($cell->value->toDateTimeImmutable(), $style),
                ColumnType::Boolean => new StringCell($this->style->booleanLabel($cell->value), $style),
                default => new StringCell($cell->value, $style),
            };
        }

        return $cells;
    }
}
