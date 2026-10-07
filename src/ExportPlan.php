<?php

declare(strict_types=1);

namespace Alif\Export;

use Alif\Export\Contracts\Exportable;

/** What one export will read and write, resolved once from the definition and the request options. */
final readonly class ExportPlan
{
    /**
     * @param  array<string, Column>  $columns
     * @param  array<string, Column>  $childColumns  Empty when the document has no children or they are not included.
     */
    private function __construct(
        public Exportable $exportable,
        public ExportOptions $options,
        public array $columns,
        public ?string $childRelation,
        public array $childColumns,
    ) {}

    /** @throws ExportException When a requested column is not in the definition (it changed after the request). */
    public static function for(Exportable $exportable, ExportOptions $options): self
    {
        $childRelation = $options->includeChildren ? $exportable->childRelation() : null;

        return new self(
            $exportable,
            $options,
            self::pick($exportable->columns(), $options->columns),
            $childRelation,
            $childRelation === null ? [] : self::pick($exportable->childColumns(), $options->childColumns),
        );
    }

    /** @return list<string> */
    public function headings(): array
    {
        return array_values(array_map(fn (Column $column): string => (string) __($column->label()), $this->allColumns()));
    }

    /** @return list<bool> */
    public function numeric(): array
    {
        return array_values(array_map(fn (Column $column): bool => $column->isNumeric(), $this->allColumns()));
    }

    /** @return array<string, Column> */
    private function allColumns(): array
    {
        return [...$this->columns, ...$this->childColumns];
    }

    /**
     * @param  array<string, Column>  $available
     * @param  list<string>  $keys
     * @return array<string, Column>
     */
    private static function pick(array $available, array $keys): array
    {
        $picked = [];

        foreach ($keys as $key) {
            $picked[$key] = $available[$key] ?? throw ExportException::unknownColumn($key);
        }

        return $picked;
    }
}
