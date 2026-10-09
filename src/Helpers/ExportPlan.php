<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Alif\Export\Contracts\Exportable;
use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\Exceptions\ExportException;
use Illuminate\Support\Str;

/** What one export will read and write, resolved once from the definition and the export DTO. */
final readonly class ExportPlan
{
    /**
     * @param  array<string, Column>  $columns
     * @param  array<string, Column>  $childColumns  Empty when the document has no children or they are not included.
     */
    private function __construct(
        public Exportable $exportable,
        public ExportCreateDTO $dto,
        public array $columns,
        public ?string $childRelation,
        public array $childColumns,
    ) {}

    /**
     * @param  bool  $lenient  A column missing from the definition becomes an empty column labelled by its key
     *                         instead of an error: the writer of an accepted request must not fail because a
     *                         discovered definition changed in between.
     *
     * @throws ExportException When a requested column is not in the definition and $lenient is false.
     */
    public static function for(Exportable $exportable, ExportCreateDTO $dto, bool $lenient = false): self
    {
        $childRelation = $dto->includeChildren ? $exportable->childRelation() : null;

        return new self(
            $exportable,
            $dto,
            self::pick($exportable->columns(), $dto->columns, $lenient),
            $childRelation,
            $childRelation === null ? [] : self::pick($exportable->childColumns(), $dto->childColumns, $lenient),
        );
    }

    /** @return list<string> */
    public function headings(): array
    {
        return array_map(fn (Column $column): string => (string) __($column->label()), $this->allColumns());
    }

    /** @return list<bool> */
    public function numeric(): array
    {
        return array_map(fn (Column $column): bool => $column->isNumeric(), $this->allColumns());
    }

    /**
     * Document columns then child columns, positionally: a child column may share its key with a document column.
     *
     * @return list<Column>
     */
    private function allColumns(): array
    {
        return [...array_values($this->columns), ...array_values($this->childColumns)];
    }

    /**
     * @param  array<string, Column>  $available
     * @param  list<string>  $keys
     * @return array<string, Column>
     */
    private static function pick(array $available, array $keys, bool $lenient): array
    {
        $picked = [];

        foreach ($keys as $key) {
            $picked[$key] = $available[$key]
                ?? ($lenient ? Column::make(Str::headline(str_replace('.', ' ', $key))) : throw ExportException::unknownColumn($key));
        }

        return $picked;
    }
}
