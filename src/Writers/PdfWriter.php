<?php

declare(strict_types=1);

namespace Alif\Export\Writers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/** dompdf renders the whole document in memory, hence the low default row cap. */
final class PdfWriter implements Writer
{
    public function write(string $title, array $headings, array $numeric, iterable $rows, string $disk, string $path): int
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
