<?php

declare(strict_types=1);

namespace Alif\Export\Helpers\Writers;

use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportStyle;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Common\Exception\IOException;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer as SpoutWriter;

/**
 * Streams rows to a local file, so memory stays flat. Text is always a StringCell: a leading "=" is never a
 * formula. Fonts, header look, number formats and column widths come from `export.style`.
 */
final class XlsxWriter implements Writer
{
    /** Excel keeps about 15 significant digits; longer numbers are written as exact text. */
    private const int MAX_SAFE_DIGITS = 15;

    public function __construct(private readonly ExportStyle $style = new ExportStyle) {}

    public function write(string $title, array $headings, array $numeric, iterable $rows, string $path): int
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

            $base = $this->baseStyle();
            $integer = (clone $base)->setFormat($this->style->integerFormat());
            $decimal = (clone $base)->setFormat($this->style->numberFormat());

            $count = 0;
            foreach ($rows as $row) {
                $writer->addRow(new Row($this->cells($row, $numeric, $base, $integer, $decimal)));
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

    /**
     * @param  list<string|int|float|null>  $row
     * @param  list<bool>  $numeric
     * @return list<NumericCell|StringCell>
     */
    private function cells(array $row, array $numeric, Style $base, Style $integer, Style $decimal): array
    {
        $cells = [];

        foreach ($row as $i => $value) {
            $number = ($numeric[$i] ?? false) ? $this->asNumber($value) : null;
            $cells[] = $number !== null
                ? new NumericCell($number, is_int($number) ? $integer : $decimal)
                : new StringCell((string) $value, $base);
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
