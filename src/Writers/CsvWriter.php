<?php

declare(strict_types=1);

namespace Alif\Export\Writers;

use Illuminate\Support\Facades\Storage;

final class CsvWriter implements Writer
{
    private const array FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public function write(string $title, array $headings, array $numeric, iterable $rows, string $disk, string $path): int
    {
        $temp = tmpfile();
        fwrite($temp, "\xEF\xBB\xBF");
        fputcsv($temp, $headings, ',', '"', '');

        $count = 0;
        foreach ($rows as $row) {
            fputcsv($temp, $this->guard($row, $numeric), ',', '"', '');
            $count++;
        }

        rewind($temp);
        Storage::disk($disk)->writeStream($path, $temp);
        fclose($temp);

        return $count;
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
