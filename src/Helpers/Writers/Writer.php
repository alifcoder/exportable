<?php

declare(strict_types=1);

namespace Alif\Export\Helpers\Writers;

use Alif\QueryFilter\Enums\FieldType;

interface Writer
{
    /**
     * Creates the file and writes the headings and all lines to it.
     *
     * @param  list<string>  $headings  The document columns.
     * @param  list<FieldType|null>  $types  Declared type per document column; null reads the type of each value.
     * @param  iterable<int, Line>  $lines  Document lines in order, each followed by its sub lines (see {@see Line}).
     * @param  string  $path  Local file the writer creates.
     * @param  array<int, int>  $widths  Longest heading per absolute column position, sub columns included; empty sizes by `$headings`.
     * @return int Lines written (excluding the heading row).
     */
    public function write(array $headings, array $types, iterable $lines, string $path, array $widths = []): int;
}
