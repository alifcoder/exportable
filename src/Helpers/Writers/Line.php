<?php

declare(strict_types=1);

namespace Alif\Export\Helpers\Writers;

use Alif\Export\Enums\ColumnType;
use Carbon\CarbonInterface;

/**
 * One written row. `$depth` is how deep it sits below its document row (0 is the document itself): writers indent a
 * sub row by that many columns and, in xlsx, make it collapsible under its parent. A heading line names the columns of
 * the sub rows that follow it.
 */
final readonly class Line
{
    /**
     * @param  list<string|int|float|bool|CarbonInterface|null>  $cells
     * @param  list<ColumnType|null>  $types  Declared type per cell; null reads the type of each value.
     */
    public function __construct(
        public int $depth,
        public array $cells,
        public array $types = [],
        public bool $heading = false,
    ) {}
}
