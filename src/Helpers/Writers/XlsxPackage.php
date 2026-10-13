<?php

declare(strict_types=1);

namespace Alif\Export\Helpers\Writers;

use Alif\Export\Exceptions\ExportException;
use ZipArchive;

/**
 * Finishes the xlsx the spreadsheet library wrote. It drops the empty comments parts the library always adds (WPS based
 * editors refuse a file with them) and turns the sub rows into an Excel outline: every sub row is grouped under the row above it and
 * starts collapsed, so the "+" next to a document row opens its items (and theirs) like the list in the app does.
 * The spreadsheet library cannot group rows, so the sheet's XML is rewritten in a stream, the row tags only.
 */
final class XlsxPackage
{
    private const string SHEET = 'xl/worksheets/sheet1.xml';

    private const int CHUNK = 1048576;

    /**
     * Opens the finished xlsx file, cleans it up and saves it again.
     *
     * @param  string  $depths  One byte per written row, row 1 first: the depth of that row.
     * @param  array<int, float>  $widths  Width in characters per column, column 1 first.
     */
    public function finish(string $path, string $depths, array $widths): void
    {
        $max = strlen($depths) === 0 ? 0 : max(array_map(ord(...), str_split($depths)));

        $zip = new ZipArchive;
        $rewritten = tempnam(sys_get_temp_dir(), 'export_sheet_');

        if ($zip->open($path) !== true || $rewritten === false) {
            throw ExportException::storageWriteFailed('cannot reopen the xlsx to finish it');
        }

        try {
            $this->dropComments($zip);

            // The sheet points at the dropped drawing, so it is always rewritten (in a stream, the rows only when grouping).
            $this->rewrite($zip, $depths, $max, $widths, $rewritten);
            $zip->addFile($rewritten, self::SHEET);

            if (! $zip->close()) {
                throw ExportException::storageWriteFailed('cannot save the finished xlsx');
            }
        } finally {
            @unlink($rewritten);
        }
    }

    /** Removes the empty comments files the library adds to every xlsx, because some editors cannot open them. */
    private function dropComments(ZipArchive $zip): void
    {
        foreach (['xl/comments1.xml', 'xl/drawings/vmlDrawing1.vml', 'xl/worksheets/_rels/sheet1.xml.rels'] as $part) {
            $zip->deleteName($part);
        }

        $types = $zip->getFromName('[Content_Types].xml');

        if ($types !== false) {
            $types = (string) preg_replace(['/<Override[^>]*comments[^>]*\/>\s*/', '/<Default[^>]*vmlDrawing[^>]*\/>\s*/'], '', $types);
            $zip->addFromString('[Content_Types].xml', $types);
        }
    }

    /**
     * Reads the sheet XML piece by piece, changes it, and writes it to a temporary file (so a big sheet never sits in memory).
     *
     * @param  array<int, float>  $widths
     */
    private function rewrite(ZipArchive $zip, string $depths, int $max, array $widths, string $to): void
    {
        $from = $zip->getStream(self::SHEET);
        $out = fopen($to, 'wb');

        if ($from === false || $out === false) {
            throw ExportException::storageWriteFailed('cannot read the xlsx sheet');
        }

        try {
            $buffer = '';
            $head = true;

            while (! feof($from)) {
                $buffer .= (string) fread($from, self::CHUNK);

                if ($head) {
                    if (! str_contains($buffer, '<sheetData')) {
                        continue;
                    }

                    $buffer = $this->head($buffer, $max, $widths);
                    $head = false;
                }

                // Hold back the last row tag: it may be cut in two.
                $cut = strrpos($buffer, '<row ');

                if ($cut === false || $cut === 0) {
                    continue;
                }

                fwrite($out, $max > 0 ? $this->rows(substr($buffer, 0, $cut), $depths) : substr($buffer, 0, $cut));
                $buffer = substr($buffer, $cut);
            }

            fwrite($out, $this->withoutDrawing($head || $max === 0 ? $buffer : $this->rows($buffer, $depths)));
        } finally {
            fclose($from);
            fclose($out);
        }
    }

    /** Removes the link to the deleted comments drawing, so the sheet does not point at a file that is gone. */
    private function withoutDrawing(string $tail): string
    {
        return (string) preg_replace('/<legacyDrawing\b[^>]*\/>/', '', $tail);
    }

    /**
     * Changes the top part of the sheet: sets the column widths and, when rows are grouped, puts the "+" button on the
     * parent row (above its items) and tells Excel how deep the groups go.
     *
     * @param  array<int, float>  $widths
     */
    private function head(string $xml, int $max, array $widths): string
    {
        $cols = '';

        foreach ($widths as $i => $width) {
            $cols .= sprintf('<col min="%1$d" max="%1$d" width="%2$s" customWidth="1"/>', $i + 1, round($width, 2));
        }

        if ($cols !== '') {
            $xml = (string) preg_replace('/<sheetData/', '<cols>'.$cols.'</cols><sheetData', $xml, 1);
        }

        if ($max === 0) {
            return $xml;
        }

        $outline = '<outlinePr summaryBelow="0"/>';

        if (preg_match('/<sheetPr\b[^>]*>/', $xml) === 1) {
            $xml = (string) preg_replace('/(<sheetPr\b[^>]*>)/', '$1'.$outline, $xml, 1);
        } else {
            $xml = (string) preg_replace('/(<worksheet\b[^>]*>)/', '$1<sheetPr>'.$outline.'</sheetPr>', $xml, 1);
        }

        if (preg_match('/<sheetFormatPr\b/', $xml) === 1) {
            return (string) preg_replace('/<sheetFormatPr\b/', '<sheetFormatPr outlineLevelRow="'.$max.'"', $xml, 1);
        }

        return (string) preg_replace('/(<cols>|<sheetData)/', '<sheetFormatPr defaultRowHeight="15" outlineLevelRow="'.$max.'"/>$1', $xml, 1);
    }

    /** Marks each row with its group level: sub rows start hidden, and a row that has sub rows starts collapsed. */
    private function rows(string $xml, string $depths): string
    {
        return (string) preg_replace_callback('/<row r="(\d+)"/', function (array $m) use ($depths): string {
            $at = (int) $m[1] - 1;
            $depth = ord($depths[$at] ?? "\0");
            $parent = ord($depths[$at + 1] ?? "\0") > $depth;
            $attributes = ($depth > 0 ? ' outlineLevel="'.$depth.'" hidden="1"' : '').($parent ? ' collapsed="1"' : '');

            return $m[0].$attributes;
        }, $xml);
    }
}
