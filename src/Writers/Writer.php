<?php

declare(strict_types=1);

namespace Alif\Export\Writers;

interface Writer
{
    /**
     * @param  list<string>  $headings
     * @param  list<bool>  $numeric
     * @param  iterable<int, list<string|int|float|null>>  $rows
     * @return int Rows written (excluding the heading row).
     */
    public function write(string $title, array $headings, array $numeric, iterable $rows, string $disk, string $path): int;
}
