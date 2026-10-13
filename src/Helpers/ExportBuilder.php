<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Alif\Export\DTO\Export\ExportColumn;
use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\DTO\Export\ExportDTO;
use Alif\Export\Enums\ExportFormat;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\Writers\Line;
use BackedEnum;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Request side: orders the list's query, checks the requested columns against what the resource yields, counts the
 * rows. Job side: reads the prepared SQL page by page and turns each model into export lines.
 */
final class ExportBuilder
{
    private const int SAMPLE_ROWS = 20;

    /** The list's query with a stable final order: the filter's own sort, then the key. */
    public function ordered(Builder $query): Builder
    {
        $ordered = clone $query;
        $ordered->setEagerLoads([]);
        $ordered->orderBy($ordered->getModel()->getQualifiedKeyName());

        return $ordered;
    }

    /**
     * Stops the export when there is no data.
     *
     * @param  int|null  $rows  The output row count when the caller already has it ({@see countRows()}).
     *
     * @throws ValidationException When the filter selects nothing: an empty file is never produced.
     */
    public function assertNotEmpty(Builder $query, ?int $rows = null): void
    {
        $empty = $rows !== null ? $rows === 0 : ! (clone $query)->reorder()->exists();

        if ($empty) {
            throw ValidationException::withMessages(['columns' => ['There is no data to export.']]);
        }
    }

    /**
     * Stops the export when it has more rows than the format allows.
     *
     * @param  int|null  $rows  The row count when the caller already has it ({@see countRows()}); the cap is then
     *                          compared to it instead of being queried again.
     *
     * @throws ValidationException
     */
    public function assertWithinCap(ExportFormat $format, Builder $query, ?int $rows = null): void
    {
        $cap = $format->maxRows();

        $exceeds = $rows !== null ? $rows > $cap : (clone $query)->reorder()->offset($cap)->limit(1)->exists();

        if ($exceeds) {
            throw ValidationException::withMessages([
                'format' => [sprintf('Too many rows for %s export (maximum %d).', $format->value, $cap)],
            ]);
        }
    }

    /** Rows the export will write: the whole filtered set. */
    public function countRows(Builder $query): int
    {
        $counted = (clone $query)->reorder()->setEagerLoads([])->select($query->getModel()->getQualifiedKeyName())->toBase();

        return (int) $query->getModel()->getConnection()->query()->fromSub($counted, 'counted')->count();
    }

    /**
     * Checks every requested key against the keys the resource yields for a sample of the list and reads each
     * column's type from the sampled values.
     *
     * @return list<ExportColumn>
     *
     * @throws ValidationException An unknown column.
     */
    public function describe(ExportDTO $request, Builder $query): array
    {
        $source = $request->source::exportSource();
        $sample = (clone $query)->reorder()->orderBy($query->getModel()->getQualifiedKeyName());
        $sample->getQuery()->offset = null;
        $rows = $sample->limit(self::SAMPLE_ROWS)->with($source['with'])->get();

        return $this->typed($request->columns, (new ResourceRows($source['resource']))->layout($rows->all(), new $source['model']));
    }

    /**
     * The lines of every document in turn, one list per document: the document's own line, then for each requested
     * list a heading line and a line per item, each item followed by its own lists. Nothing here reads the request or the signed-in user.
     *
     * @return Generator<int, list<Line>>
     *
     * @throws ExportException
     */
    public function documents(ExportCreateDTO $dto): Generator
    {
        $source = $dto->source::exportSource();
        $query = $dto->query;
        $connection = DB::connection($query->connection);
        $model = new $source['model'];
        $resource = new ResourceRows($source['resource']);
        $with = $source['with'];
        $chunk = max(1, ExportConfig::int('chunk_size'));
        $cap = $dto->format->maxRows();
        $tree = ColumnNode::tree($dto->columns);
        $offset = 0;
        $emitted = 0;

        while (true) {
            $records = $connection->select(sprintf('%s limit %d offset %d', $query->sql, $chunk, $offset), $query->bindings);

            if ($records === []) {
                return;
            }

            $models = $model->newCollection(array_map(fn (object $record): Model => $model->newFromBuilder((array) $record, $query->connection), $records));
            // Eloquent only arms the guard for result sets with more than one model; arm it for every row.
            $models->each(fn (Model $m) => $m->preventsLazyLoading = Model::preventsLazyLoading());
            $models->load($with);

            foreach ($models as $row) {
                $lines = $this->lines($tree, $resource->read($row));
                $emitted += count($lines);

                if ($emitted > $cap) {
                    throw ExportException::rowLimitExceeded($cap);
                }

                yield $lines;
            }

            $offset += count($records);

            if (count($records) < $chunk) {
                return;
            }
        }
    }

    /**
     * Checks that every requested column exists and gives each one its type.
     *
     * @param  list<ExportColumn>  $requested
     * @param  array{values: array<string, scalar|null>, lists: array<string, array<string, mixed>>}  $layout
     * @return list<ExportColumn>
     *
     * @throws ValidationException
     */
    private function typed(array $requested, array $layout): array
    {
        $unknown = array_values(array_filter($requested, fn (ExportColumn $c): bool => ! $this->known($c, $layout)));

        if ($unknown !== []) {
            throw ValidationException::withMessages(['columns' => array_map(
                fn (ExportColumn $c): string => sprintf('Unknown column "%s".', implode('.', [...$c->groups, $c->key])),
                $unknown,
            )]);
        }

        return array_map(
            fn (ExportColumn $c): ExportColumn => $c->withType(ResourceRows::typeOf($c->key, ($this->levelOf($c, $layout) ?? ['values' => []])['values'][$c->key] ?? null)),
            $requested,
        );
    }

    /**
     * The layout of the list a column belongs to: the document itself, or the items of its list, however deep.
     *
     * @param  array{values: array<string, scalar|null>, lists: array<string, array<string, mixed>>}  $layout
     * @return array{values: array<string, scalar|null>, lists: array<string, array<string, mixed>>}|null
     */
    private function levelOf(ExportColumn $column, array $layout): ?array
    {
        foreach ($column->groups as $group) {
            $layout = $layout['lists'][$group] ?? null;

            if ($layout === null) {
                return null;
            }
        }

        return $layout;
    }

    /**
     * A list that no sampled document has items for yields no keys to check: the list itself must exist in the
     * resource, its keys are then accepted untyped.
     *
     * @param  array{values: array<string, scalar|null>, lists: array<string, array<string, mixed>>}  $layout
     */
    private function known(ExportColumn $column, array $layout): bool
    {
        $level = $this->levelOf($column, $layout);

        if ($level === null) {
            return false;
        }

        if ($column->groups !== [] && $level['values'] === [] && $level['lists'] === []) {
            return true;
        }

        return array_key_exists($column->key, $level['values']) || $this->underNullObject($column->key, $level['values']);
    }

    /**
     * `partner.name` cannot be seen when the sampled rows have no partner: the resource renders `partner` as null. A key
     * below such a null object is accepted untyped; the resource decides what it holds when a row has one.
     *
     * @param  array<string, scalar|null>  $available
     */
    private function underNullObject(string $key, array $available): bool
    {
        for ($dot = strrpos($key, '.'); $dot !== false; $dot = strrpos($key, '.')) {
            $key = substr($key, 0, $dot);

            if (array_key_exists($key, $available)) {
                return $available[$key] === null;
            }
        }

        return false;
    }

    /**
     * Turns one document into lines: its own line, then a heading line and the item lines of each list.
     *
     * @param  array{values: array<string, scalar|null>, lists: array<string, list<array<string, mixed>>>}  $read
     * @return list<Line>
     */
    private function lines(ColumnNode $node, array $read): array
    {
        $lines = [new Line($node->depth, array_map(
            fn (ExportColumn $column): string|int|float|bool|null => $this->normalize($read['values'][$column->key] ?? null, $column->key),
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

    /** Turns a value into a simple cell value. An enum becomes its value, an array or object is an error. */
    private function normalize(mixed $value, string $key): string|int|float|bool|null
    {
        return match (true) {
            $value === null => null,
            $value instanceof BackedEnum => $value->value,
            is_scalar($value) => $value,
            default => throw ExportException::invalidColumnValue($key),
        };
    }
}
