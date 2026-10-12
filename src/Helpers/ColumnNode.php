<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Alif\Export\DTO\Export\ExportColumn;
use Alif\Export\Enums\ColumnType;

/**
 * The requested columns as a tree: the columns of one level (a document, or the items of one list) and the lists
 * below it. Built from the flat, ordered column list the task carries.
 */
final class ColumnNode
{
    /**
     * @param  list<ExportColumn>  $columns
     * @param  array<string, ColumnNode>  $children  By list key, in the order the client asked.
     */
    private function __construct(
        public readonly int $depth,
        public array $columns = [],
        public array $children = [],
    ) {}

    /** @param list<ExportColumn> $columns */
    public static function tree(array $columns): self
    {
        $root = new self(0);

        foreach ($columns as $column) {
            $node = $root;

            foreach ($column->groups as $group) {
                $node = $node->children[$group] ??= new self($node->depth + 1);
            }

            $node->columns[] = $column;
        }

        return $root;
    }

    /** @return list<string> */
    public function headings(): array
    {
        return array_map(fn (ExportColumn $column): string => $column->label, $this->columns);
    }

    /** @return list<ColumnType|null> */
    public function types(): array
    {
        return array_map(fn (ExportColumn $column) => $column->type, $this->columns);
    }

    /**
     * Longest heading per absolute column position, a sub row sitting `depth` columns to the right.
     *
     * @return array<int, int>
     */
    public function widths(): array
    {
        $widths = [];
        $this->collect($widths);

        return $widths;
    }

    /** @param array<int, int> $widths */
    private function collect(array &$widths): void
    {
        foreach ($this->columns as $i => $column) {
            $at = $this->depth + $i;
            $widths[$at] = max($widths[$at] ?? 0, mb_strlen($column->label));
        }

        foreach ($this->children as $child) {
            $child->collect($widths);
        }
    }
}
