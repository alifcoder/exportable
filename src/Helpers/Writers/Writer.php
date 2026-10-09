<?php

declare(strict_types=1);

namespace Alif\Export\Helpers\Writers;

interface Writer
{
    /**
     * @param  list<string>  $headings
     * @param  list<bool>  $numeric
     * @param  iterable<int, list<string|int|float|null>>  $rows
     * @param  string  $path  Local file the writer creates.
     * @return int Rows written (excluding the heading row).
     */
    public function write(string $title, array $headings, array $numeric, iterable $rows, string $path): int;
}
