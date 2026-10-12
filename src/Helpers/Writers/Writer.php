<?php

declare(strict_types=1);

namespace Alif\Export\Helpers\Writers;

use Alif\Export\Enums\ColumnType;
use Carbon\CarbonInterface;

interface Writer
{
    /**
     * @param  list<string>  $headings
     * @param  list<ColumnType|null>  $types  Declared type per column; null reads the type of each value.
     * @param  iterable<int, list<string|int|float|bool|CarbonInterface|null>>  $rows
     * @param  string  $path  Local file the writer creates.
     * @return int Rows written (excluding the heading row).
     */
    public function write(array $headings, array $types, iterable $rows, string $path): int;
}
