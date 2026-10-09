<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

/** Typed reader of the host's `export.style` settings: date formats, csv layout, fonts, header look, number formats, widths. */
final readonly class ExportStyle
{
    public function dateFormat(): string
    {
        return ExportConfig::string('style.date_format');
    }

    public function dateTimeFormat(): string
    {
        return ExportConfig::string('style.datetime_format');
    }

    /** Single-byte field separator of csv files. */
    public function csvDelimiter(): string
    {
        return ExportConfig::string('style.csv.delimiter');
    }

    /** Single-byte quote character of csv fields. */
    public function csvEnclosure(): string
    {
        return ExportConfig::string('style.csv.enclosure');
    }

    /** Line ending written after every csv row. */
    public function csvLineEnding(): string
    {
        return ExportConfig::string('style.csv.line_ending');
    }

    /** Whether csv files start with a UTF-8 byte order mark (Excel needs it to read UTF-8). */
    public function csvBom(): bool
    {
        return ExportConfig::bool('style.csv.bom');
    }

    public function fontName(): string
    {
        return ExportConfig::string('style.xlsx.font.name');
    }

    public function fontSize(): int
    {
        return ExportConfig::int('style.xlsx.font.size');
    }

    public function headerBold(): bool
    {
        return ExportConfig::bool('style.xlsx.header.bold');
    }

    public function headerFontSize(): int
    {
        return ExportConfig::int('style.xlsx.header.font_size');
    }

    /** ARGB hex, e.g. FF000000. */
    public function headerFontColor(): string
    {
        return ExportConfig::string('style.xlsx.header.font_color');
    }

    /** ARGB hex, e.g. FFE8EEF7. */
    public function headerBackground(): string
    {
        return ExportConfig::string('style.xlsx.header.background');
    }

    /** Excel number format of decimal cells, e.g. `#,##0.00`. */
    public function numberFormat(): string
    {
        return ExportConfig::string('style.xlsx.number_format');
    }

    /** Excel number format of whole-number cells, e.g. `#,##0`. */
    public function integerFormat(): string
    {
        return ExportConfig::string('style.xlsx.integer_format');
    }

    public function minColumnWidth(): float
    {
        return (float) ExportConfig::get('style.xlsx.column_width.min');
    }

    public function maxColumnWidth(): float
    {
        return (float) ExportConfig::get('style.xlsx.column_width.max');
    }

    /** Characters added to the heading length when sizing a column. */
    public function columnPadding(): float
    {
        return (float) ExportConfig::get('style.xlsx.column_width.padding');
    }
}
