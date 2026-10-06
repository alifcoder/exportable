<?php

declare(strict_types=1);

namespace Alif\Export;

use Alif\Export\Enums\ExportFormat;
use Alif\Export\Support\StringValueBinder;
use Barryvdh\DomPDF\Facade\Pdf;
use Generator;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;

final class ExportWriter
{
    private const array FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    private const int MAX_SAFE_DIGITS = 15;

    /**
     * @param  list<string>  $headings
     * @param  list<bool>  $numeric
     * @param  iterable<int, list<string|int|float|null>>  $rows
     * @return int Rows written (excluding the heading row).
     */
    public function store(
        ExportFormat $format,
        string $title,
        array $headings,
        array $numeric,
        iterable $rows,
        string $disk,
        string $path,
    ): int {
        return match ($format) {
            ExportFormat::Csv => $this->storeCsv($headings, $numeric, $rows, $disk, $path),
            ExportFormat::Xlsx => $this->storeXlsx($headings, $numeric, $rows, $disk, $path),
            ExportFormat::Pdf => $this->storePdf($title, $headings, $numeric, $rows, $disk, $path),
        };
    }

    /**
     * @param  list<string>  $headings
     * @param  list<bool>  $numeric
     * @param  iterable<int, list<string|int|float|null>>  $rows
     */
    private function storeCsv(array $headings, array $numeric, iterable $rows, string $disk, string $path): int
    {
        $temp = tmpfile();
        fwrite($temp, "\xEF\xBB\xBF");
        fputcsv($temp, $headings, ',', '"', '');

        $count = 0;
        foreach ($rows as $row) {
            fputcsv($temp, $this->csvRow($row, $numeric), ',', '"', '');
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
    private function csvRow(array $row, array $numeric): array
    {
        $out = [];

        foreach ($row as $i => $cell) {
            // Exempt from formula neutralising only when the value really is a number, not by column flag alone.
            $isNumeric = ($numeric[$i] ?? false) && is_numeric($cell);
            $out[] = ! $isNumeric && is_string($cell) && $cell !== '' && in_array($cell[0], self::FORMULA_PREFIXES, true)
                ? "'" . $cell
                : $cell;
        }

        return $out;
    }

    /**
     * @param  list<string>  $headings
     * @param  list<bool>  $numeric
     * @param  iterable<int, list<string|int|float|null>>  $rows
     */
    private function storeXlsx(array $headings, array $numeric, iterable $rows, string $disk, string $path): int
    {
        $count = 0;
        $generator = (function () use ($rows, $numeric, &$count): Generator {
            foreach ($rows as $row) {
                $count++;

                yield $this->xlsxRow($row, $numeric);
            }
        })();

        $export = new class($generator, $headings, new StringValueBinder) implements FromGenerator, WithCustomValueBinder, WithHeadings
        {
            /** @param list<string> $headings */
            public function __construct(
                private readonly Generator $generator,
                private readonly array $headings,
                private readonly StringValueBinder $binder,
            ) {}

            public function generator(): Generator
            {
                return $this->generator;
            }

            /** @return list<string> */
            public function headings(): array
            {
                return $this->headings;
            }

            public function bindValue(Cell $cell, mixed $value): bool
            {
                return $this->binder->bindValue($cell, $value);
            }
        };

        Excel::store($export, $path, $disk, ExcelWriter::XLSX);

        return $count;
    }

    /**
     * @param  list<string|int|float|null>  $row
     * @param  list<bool>  $numeric
     * @return list<string|int|float|null>
     */
    private function xlsxRow(array $row, array $numeric): array
    {
        $out = [];

        foreach ($row as $i => $cell) {
            if (! ($numeric[$i] ?? false)) {
                // Non-numeric columns are exact text: Excel would round a 17-digit number.
                $out[] = is_int($cell) || is_float($cell) ? (string) $cell : $cell;

                continue;
            }

            if (is_int($cell)) {
                $out[] = $this->digits((string) $cell) <= self::MAX_SAFE_DIGITS ? $cell : (string) $cell;

                continue;
            }

            $out[] = is_string($cell) && is_numeric($cell) && $this->digits($cell) <= self::MAX_SAFE_DIGITS
                ? $cell + 0
                : $cell;
        }

        return $out;
    }

    private function digits(string $number): int
    {
        return strlen(ltrim(str_replace(['-', '+', '.'], '', $number), '0'));
    }

    /**
     * @param  list<string>  $headings
     * @param  list<bool>  $numeric
     * @param  iterable<int, list<string|int|float|null>>  $rows
     */
    private function storePdf(string $title, array $headings, array $numeric, iterable $rows, string $disk, string $path): int
    {
        $list = [];
        foreach ($rows as $row) {
            $list[] = $row;
        }

        $output = Pdf::setOptions([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
        ])
            ->loadView('export::pdf', ['title' => $title, 'headings' => $headings, 'numeric' => $numeric, 'rows' => $list])
            ->setPaper('a4', 'landscape')
            ->output();

        Storage::disk($disk)->put($path, $output);

        return count($list);
    }
}
