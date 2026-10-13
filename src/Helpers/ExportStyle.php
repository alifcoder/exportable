<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

/** Typed reader of the host's `export.style` settings: date formats, csv layout, fonts, header look, number formats, widths. */
final readonly class ExportStyle
{
    /** PHP `date()` format of date values in csv and text. */
    public function dateFormat(): string
    {
        return ExportConfig::string('style.date_format');
    }

    /** PHP `date()` format of date-time values in csv and text. */
    public function dateTimeFormat(): string
    {
        return ExportConfig::string('style.datetime_format');
    }

    /** PHP `date()` format of time values in csv and text. */
    public function timeFormat(): string
    {
        return ExportConfig::string('style.time_format');
    }

    /** The text of a boolean cell, translated when it is a translation key. */
    public function booleanLabel(bool $value): string
    {
        return (string) __(ExportConfig::string('style.boolean.'.($value ? 'true' : 'false')));
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

    /** Font name of all xlsx cells. */
    public function fontName(): string
    {
        return ExportConfig::string('style.xlsx.font.name');
    }

    /** Font size of the xlsx cells. */
    public function fontSize(): int
    {
        return ExportConfig::int('style.xlsx.font.size');
    }

    /** Whether the xlsx header is bold. */
    public function headerBold(): bool
    {
        return ExportConfig::bool('style.xlsx.header.bold');
    }

    /** Font size of the xlsx header. */
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

    /** Excel number format of percent cells, e.g. `0.00"%"` (25 is shown as 25.00%). */
    public function percentFormat(): string
    {
        return ExportConfig::string('style.xlsx.percent_format');
    }

    /** Excel number format of currency cells, e.g. `#,##0.00`. */
    public function currencyFormat(): string
    {
        return ExportConfig::string('style.xlsx.currency_format');
    }

    /** Excel date format of date cells, e.g. `yyyy-mm-dd`. */
    public function dateCellFormat(): string
    {
        return ExportConfig::string('style.xlsx.date_format');
    }

    /** Excel format of date-time cells, e.g. `yyyy-mm-dd hh:mm:ss`. */
    public function dateTimeCellFormat(): string
    {
        return ExportConfig::string('style.xlsx.datetime_format');
    }

    /** Excel format of time cells, e.g. `hh:mm:ss`. */
    public function timeCellFormat(): string
    {
        return ExportConfig::string('style.xlsx.time_format');
    }

    /** The narrowest an xlsx column can be, in characters. */
    public function minColumnWidth(): float
    {
        return (float) ExportConfig::get('style.xlsx.column_width.min');
    }

    /** The widest an xlsx column can be, in characters. */
    public function maxColumnWidth(): float
    {
        return (float) ExportConfig::get('style.xlsx.column_width.max');
    }

    /** Characters added to the longest heading or value when sizing a column. */
    public function columnPadding(): float
    {
        return (float) ExportConfig::get('style.xlsx.column_width.padding');
    }
}
