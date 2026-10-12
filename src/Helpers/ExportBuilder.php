<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Alif\Export\DTO\Export\ExportColumn;
use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\DTO\Export\ExportRequest;
use Alif\Export\Enums\ExportFormat;
use Alif\Export\Exceptions\ExportException;
use BackedEnum;
use Closure;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Request side: orders the list's query, checks the requested columns against what the resource yields, counts the
 * output rows. Job side: reads the prepared SQL page by page and turns each model into export lines.
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
     * @param  int|null  $rows  The output row count when the caller already has it ({@see countRows()}); the
     *                          cap is then compared to it instead of being queried again.
     *
     * @throws ValidationException
     */
    public function assertWithinCap(ExportFormat $format, ?string $childRelation, Builder $query, ?int $rows = null): void
    {
        $cap = $format->maxRows();

        $exceeds = match (true) {
            $rows !== null => $rows > $cap,
            $childRelation === null => (clone $query)->reorder()->offset($cap)->limit(1)->exists(),
            // With children the output row count is at least the document count, so one count query covers both.
            default => $this->outputRows($query, $childRelation) > $cap,
        };

        if ($exceeds) {
            throw ValidationException::withMessages([
                'format' => [sprintf('Too many rows for %s export (maximum %d).', $format->value, $cap)],
            ]);
        }
    }

    /** Output rows the export will write, within the list's own window (limit, page) when it has one. */
    public function countRows(Builder $query, ?string $childRelation): int
    {
        if ($childRelation !== null) {
            return $this->outputRows($query, $childRelation);
        }

        $counted = (clone $query)->reorder()->setEagerLoads([])->select($query->getModel()->getQualifiedKeyName())->toBase();

        return (int) $query->getModel()->getConnection()->query()->fromSub($counted, 'counted')->count();
    }

    /**
     * Checks every requested key against the keys the resource yields for a sample of the list and reads each
     * column's type from the sampled values.
     *
     * @return array{0: list<ExportColumn>, 1: list<ExportColumn>} Document columns, child columns.
     *
     * @throws ValidationException An unknown column.
     */
    public function describe(ExportRequest $request, Builder $query): array
    {
        $source = $request->source::exportSource();
        $sample = (clone $query)->reorder()->orderBy($query->getModel()->getQualifiedKeyName());
        $sample->getQuery()->offset = null;
        $rows = $sample->limit(self::SAMPLE_ROWS)->with($this->eagerLoads($source['with'], $request->childRelation, $query->getModel()))->get();

        [$parents, $children] = (new ResourceRows($source['resource'], $request->childRelation))->layout($rows->all(), new $source['model']);

        return [
            $this->typed($request->columns, $parents, 'columns'),
            $this->typed($request->childColumns, $children, 'columns.'.$request->childRelation),
        ];
    }

    /**
     * Reads the prepared SQL page by page, `chunk_size` models at a time, and yields one line per document (one per
     * child when children are exported). Nothing here reads the request or the signed-in user.
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
        $resource = new ResourceRows($source['resource'], $dto->childRelation);
        $with = $this->eagerLoads($source['with'], $dto->childRelation, $model);
        $chunk = max(1, ExportConfig::int('chunk_size'));
        $cap = $dto->format->maxRows();
        $blankChild = array_fill(0, count($dto->childColumns), null);
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
                [$values, $children] = $resource->split($row);
                $base = $this->values($dto->columns, $values);

                $lines = match (true) {
                    $dto->childRelation === null => [$base],
                    $children === [] => [[...$base, ...$blankChild]],
                    default => array_map(fn (array $child): array => [...$base, ...$this->values($dto->childColumns, $child)], $children),
                };

                foreach ($lines as $line) {
                    if (++$emitted > $cap) {
                        throw ExportException::rowLimitExceeded($cap);
                    }

                    yield $line;
                }
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
    private function typed(array $requested, array $available, string $attribute): array
    {
        $unknown = array_values(array_filter($requested, fn (ExportColumn $c): bool => ! array_key_exists($c->key, $available) && ! $this->underNullObject($c->key, $available)));

        if ($unknown !== []) {
            throw ValidationException::withMessages([$attribute => array_map(
                fn (ExportColumn $c): string => sprintf('Unknown column "%s".', $c->key),
                $unknown,
            )]);
        }

        return array_map(fn (ExportColumn $c): ExportColumn => $c->withType(ResourceRows::typeOf($c->key, $available[$c->key] ?? null)), $requested);
    }

    /**
     * The requested child relation must be a HasMany that the model itself declares. Only such a method is ever
     * called: a key such as `delete` or `save` must never reach an Eloquent method of the same name.
     *
     * @param  Builder<Model>  $builder
     *
     * @throws ValidationException
     */
    public function assertChildRelation(ExportRequest $request, Builder $builder): void
    {
        $relation = $request->childRelation;

        if ($relation === null) {
            return;
        }

        if (! $this->isHasManyMethod($builder->getModel(), $relation)) {
            throw ValidationException::withMessages(['columns' => [sprintf('"%s" is not a list of this document.', $relation)]]);
        }
    }

    private function isHasManyMethod(Model $model, string $name): bool
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) || ! method_exists($model, $name)) {
            return false;
        }

        $method = new ReflectionMethod($model, $name);
        $type = $method->getReturnType();

        return $method->isPublic()
            && ! $method->isStatic()
            && $method->getNumberOfRequiredParameters() === 0
            && $method->getDeclaringClass()->getName() !== Model::class
            && $type instanceof ReflectionNamedType
            && is_a($type->getName(), HasMany::class, true);
    }

    /** Output rows when children are flattened: one per child, or one for a childless document. */
    private function outputRows(Builder $query, string $childRelation): int
    {
        $alias = Str::snake($childRelation).'_count';
        $counted = (clone $query)
            ->reorder()
            ->setEagerLoads([])
            ->select($query->getModel()->getQualifiedKeyName())
            ->withCount($childRelation)
            ->toBase();

        return (int) $query->getModel()->getConnection()->query()
            ->fromSub($counted, 'counted')
            ->selectRaw(sprintf('coalesce(sum(case when %1$s > 0 then %1$s else 1 end), 0) as total', $alias))
            ->value('total');
    }

    /**
     * `owner.name` cannot be seen when the sampled rows have no owner: the resource renders `owner` as null. A key
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
     * The source's relations plus the child relation, which the resource needs loaded to list the children.
     *
     * @param  array<int|string, string|Closure>  $with
     * @return array<int|string, string|Closure>
     */
    private function eagerLoads(array $with, ?string $childRelation, Model $model): array
    {
        if ($childRelation === null) {
            return $with;
        }

        $constrained = $with[$childRelation] ?? null;
        $with = array_filter($with, fn (mixed $value, int|string $name): bool => ($name === $childRelation) === false && $value !== $childRelation, ARRAY_FILTER_USE_BOTH);

        $with[$childRelation] = function ($query) use ($constrained): void {
            if ($constrained instanceof Closure) {
                $constrained($query);
            }

            // A child table without its key column (a pivot-like table) keeps the database order.
            if (Schema::connection($query->getModel()->getConnectionName())->hasColumn($query->getModel()->getTable(), $query->getModel()->getKeyName())) {
                $query->orderBy($query->getModel()->getQualifiedKeyName());
            }
        };

        return $with;
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
