<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Alif\Export\DTO\Export\ExportColumn;
use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\Writers\Line;
use BackedEnum;
use Generator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Reads the prepared query chunk by chunk (inside the job) and turns every document into the lines of the file. It
 * reads nothing from the request or the signed-in user.
 */
final class DocumentReader
{
    /**
     * The lines of each document in turn: the document's own line, then for every requested list a heading line and a
     * line per item, each item followed by its own lists.
     *
     * @return Generator<int, list<Line>>
     *
     * @throws ExportException When the lines pass the format's row cap.
     */
    public function documents(ExportCreateDTO $dto): Generator
    {
        $resource = new ResourceRows($dto->resource);
        $tree = ColumnNode::tree($dto->columns);
        $cap = $dto->format->maxRows();
        $written = 0;

        foreach ($this->models($dto) as $model) {
            $lines = $this->lines($tree, $resource->read($model));
            $written += count($lines);

            if ($written > $cap) {
                throw ExportException::rowLimitExceeded($cap);
            }

            yield $lines;
        }
    }

    /**
     * The models of the prepared query, loaded `chunk_size` at a time with the relations the resource touches.
     *
     * @return Generator<int, Model>
     */
    private function models(ExportCreateDTO $dto): Generator
    {
        $query = $dto->query;
        $connection = DB::connection($query->connection);
        $model = new $dto->model;
        $chunk = max(1, ExportConfig::int('chunk_size'));

        for ($offset = 0; ; $offset += $chunk) {
            $records = $connection->select(sprintf('%s limit %d offset %d', $query->sql, $chunk, $offset), $query->bindings);

            $models = $model->newCollection(array_map(fn (object $record): Model => $model->newFromBuilder((array) $record, $query->connection), $records));
            // Eloquent only arms the lazy loading guard for result sets with more than one model; arm it for every row.
            $models->each(fn (Model $m) => $m->preventsLazyLoading = Model::preventsLazyLoading());
            $models->load($dto->relations);

            yield from $models;

            if (count($records) < $chunk) {
                return;
            }
        }
    }

    /**
     * One document (or list item) as lines: its own line, then a heading line and the item lines of each list below it.
     *
     * @param  array{values: array<string, scalar|null>, lists: array<string, list<array<string, mixed>>>}  $read
     * @return list<Line>
     */
    private function lines(ColumnNode $node, array $read): array
    {
        $lines = [new Line($node->depth, array_map(
            fn (ExportColumn $column): string|int|float|bool|null => $this->cell($read['values'][$column->key] ?? null),
            $node->columns,
        ), $node->types())];

        foreach ($node->children as $key => $child) {
            $items = $read['lists'][$key] ?? [];

            // A list without items writes nothing: no heading, nothing to expand.
            if ($items === []) {
                continue;
            }

            $lines[] = new Line($child->depth, $child->headings(), [], true);

            foreach ($items as $item) {
                array_push($lines, ...$this->lines($child, $item));
            }
        }

        return $lines;
    }

    /**
     * A value as a simple cell value: an enum becomes its value; anything that cannot be a cell (an array or object) is left empty.
     */
    private function cell(mixed $value): string|int|float|bool|null
    {
        return match (true) {
            $value === null => null,
            $value instanceof BackedEnum => $value->value,
            is_scalar($value) => $value,
            default => null,
        };
    }
}
