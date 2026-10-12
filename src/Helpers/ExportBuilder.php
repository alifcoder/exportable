<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Alif\Export\DTO\Export\ExportColumn;
use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\DTO\Export\ExportRequest;
use Alif\Export\Enums\ExportFormat;
use Alif\Export\Exceptions\ExportException;
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

    /** Rows the export will write, within the list's own window (limit, page) when it has one. */
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
    public function describe(ExportRequest $request, Builder $query): array
    {
        $source = $request->source::exportSource();
        $sample = (clone $query)->reorder()->orderBy($query->getModel()->getQualifiedKeyName());
        $sample->getQuery()->offset = null;
        $rows = $sample->limit(self::SAMPLE_ROWS)->with($source['with'])->get();

        return $this->typed($request->columns, (new ResourceRows($source['resource']))->layout($rows->all(), new $source['model']));
    }

    /**
     * Reads the prepared SQL page by page, `chunk_size` models at a time, and yields one line per document. Nothing here reads the request or the signed-in user.
     *
     * @return Generator<int, list<string|int|float|bool|null>>
     *
     * @throws ExportException
     */
    public function rows(ExportCreateDTO $dto): Generator
    {
        $source = $dto->source::exportSource();
        $query = $dto->query;
        $connection = DB::connection($query->connection);
        $model = new $source['model'];
        $resource = new ResourceRows($source['resource']);
        $with = $source['with'];
        $chunk = max(1, ExportConfig::int('chunk_size'));
        $cap = $dto->format->maxRows();
        $offset = $query->offset;
        $remaining = $query->limit;
        $emitted = 0;

        while ($remaining === null || $remaining > 0) {
            $size = $remaining === null ? $chunk : min($chunk, $remaining);
            $records = $connection->select(sprintf('%s limit %d offset %d', $query->sql, $size, $offset), $query->bindings);

            if ($records === []) {
                return;
            }

            $models = $model->newCollection(array_map(fn (object $record): Model => $model->newFromBuilder((array) $record, $query->connection), $records));
            // Eloquent only arms the guard for result sets with more than one model; arm it for every row.
            $models->each(fn (Model $m) => $m->preventsLazyLoading = Model::preventsLazyLoading());
            $models->load($with);

            foreach ($models as $row) {
                if (++$emitted > $cap) {
                    throw ExportException::rowLimitExceeded($cap);
                }

                yield $this->values($dto->columns, $resource->values($row));
            }

            $offset += count($records);
            $remaining = $remaining === null ? null : $remaining - count($records);

            if (count($records) < $size) {
                return;
            }
        }
    }

    /**
     * @param  list<ExportColumn>  $requested
     * @param  array<string, scalar|null>  $available
     * @return list<ExportColumn>
     *
     * @throws ValidationException
     */
    private function typed(array $requested, array $available): array
    {
        $unknown = array_values(array_filter($requested, fn (ExportColumn $c): bool => ! array_key_exists($c->key, $available) && ! $this->underNullObject($c->key, $available)));

        if ($unknown !== []) {
            throw ValidationException::withMessages(['columns' => array_map(
                fn (ExportColumn $c): string => sprintf('Unknown column "%s".', $c->key),
                $unknown,
            )]);
        }

        return array_map(fn (ExportColumn $c): ExportColumn => $c->withType(ResourceRows::typeOf($c->key, $available[$c->key] ?? null)), $requested);
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
     * @param  list<ExportColumn>  $columns
     * @param  array<string, scalar|null>  $values
     * @return list<string|int|float|bool|null>
     */
    private function values(array $columns, array $values): array
    {
        return array_map(fn (ExportColumn $column): string|int|float|bool|null => $this->normalize($values[$column->key] ?? null, $column->key), $columns);
    }

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
