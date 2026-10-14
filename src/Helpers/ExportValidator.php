<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Alif\Export\DTO\Export\ExportColumn;
use Alif\Export\Enums\ExportFormatEnum;
use Alif\Export\Exceptions\ExportException;
use Alif\QueryFilter\Enums\FieldType;
use Closure;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use ReflectionFunction;

/**
 * Every check an export goes through before anything is queued: the request, the filter's query (pageable, plain
 * eager loads, not empty, within the format's cap) and the columns (checked against what the resource yields, typed).
 * Stateless; `HandlesExport` calls it. The received query is only read.
 */
final class ExportValidator
{
    /** Rows of the query rendered to check the columns. */
    private const int SAMPLE_ROWS = 20;

    /** Most rows read to find a column the first sample did not show (a field the resource adds only for some rows). */
    private const int DISCOVERY_ROWS = 500;

    /** Rows read per step after the first sample; reading stops at the step that shows every requested column. */
    private const int DISCOVERY_CHUNK = 50;

    /** Lists inside lists, counted from the document. */
    private const int MAX_DEPTH = 5;

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, array<int, mixed>>  $rules
     *
     * @throws ExportException 422 with the field errors.
     */
    public static function assertValidRequest(array $parameters, array $rules): void
    {
        try {
            Validator::make($parameters, $rules)->validate();
        } catch (ValidationException $e) {
            throw ExportException::invalidRequest($e->errors());
        }
    }

    /**
     * The job appends `limit n offset m` to the compiled SQL, so the query must not have its own page, and not group
     * or union rows: paging over those is not stable (and an added ORDER BY would not be valid).
     *
     * @param  Builder<*>  $query
     *
     * @throws ExportException
     */
    public static function assertPageable(Builder $query): void
    {
        $base = $query->getQuery();

        if ($base->limit !== null || $base->offset !== null) {
            throw ExportException::unpageableQuery('limit_or_offset');
        }

        if ($base->groups !== null || $base->havings !== null || $base->unions !== null) {
            throw ExportException::unpageableQuery('group_or_union');
        }
    }

    /**
     * Ends the order with the model's key. The chunks follow each other by offset, so rows that tie on the host's sort
     * (`-created_at`) must keep one position, or a chunk boundary repeats or skips a row; an unsorted query gets an order
     * too. Nothing is added when the key already ends the order.
     *
     * @param  Builder<*>  $query
     */
    public static function orderByKey(Builder $query): void
    {
        // A SELECT DISTINCT is used as the host wrote it: it only accepts an order over its selected columns.
        if ($query->getQuery()->distinct !== false) {
            return;
        }

        $model = $query->getModel();
        $last = Arr::last((array) $query->getQuery()->orders);
        $column = is_array($last) ? ($last['column'] ?? null) : null;

        if (is_string($column) && in_array($column, [$model->getQualifiedKeyName(), $model->getKeyName()], true)) {
            return;
        }

        $query->orderBy($model->getQualifiedKeyName());
    }

    /**
     * How many documents the query selects.
     *
     * @param  Builder<*>  $query
     */
    public static function countRows(Builder $query): int
    {
        $keys = (clone $query)->reorder()->select($query->getModel()->getQualifiedKeyName())->toBase();

        return (int) $query->getModel()->getConnection()->query()->fromSub($keys, 'counted')->count();
    }

    /**
     * An empty file is never produced.
     *
     * @param  Builder<*>  $query
     * @param  int|null  $count  The count when the caller already has it; otherwise one row is looked for.
     *
     * @throws ExportException
     */
    public static function assertNotEmpty(Builder $query, ?int $count): void
    {
        $empty = $count !== null ? $count === 0 : ! (clone $query)->reorder()->exists();

        if ($empty) {
            throw ExportException::noData();
        }
    }

    /**
     * Stops an export that has more documents than the format can hold.
     *
     * @param  Builder<*>  $query
     * @param  int|null  $count  The count when the caller already has it; otherwise one row past the cap is looked for.
     *
     * @throws ExportException
     */
    public static function assertWithinRowLimit(ExportFormatEnum $format, Builder $query, ?int $count): void
    {
        $cap = $format->maxRows();
        $exceeds = $count !== null ? $count > $cap : (clone $query)->reorder()->offset($cap)->limit(1)->exists();

        if ($exceeds) {
            throw ExportException::tooManyRows($format, $cap);
        }
    }

    /**
     * The names the query eager loads, for the job to repeat. A constrained eager load cannot be repeated.
     *
     * @param  Builder<*>  $query
     * @return list<string>
     *
     * @throws ExportException An eager load with a constraint of its own.
     */
    public static function validateEagerLoads(Builder $query): array
    {
        $relations = [];

        foreach ($query->getEagerLoads() as $name => $constraint) {
            if (! self::isPlainEagerLoad($constraint)) {
                throw ExportException::unsupportedEagerLoad((string) $name);
            }

            $relations[] = (string) $name;
        }

        return $relations;
    }

    /**
     * @param  Builder<*>  $query
     * @param  class-string  $resource
     * @param  array<string, mixed>  $requested  `key => heading`, and `list => {key => heading, ...}` nested.
     * @param  array<string, FieldType>  $declared  The type a filter's `Field` declares, by the column's dotted path; it wins over the guess.
     * @return list<ExportColumn> The requested columns in the order sent, each with its type.
     *
     * @throws ExportException A column that cannot be exported or the resource does not yield, or a resource that fails.
     */
    public static function validateColumns(Builder $query, string $resource, array $requested, array $declared = []): array
    {
        $columns = self::flattenColumns($requested, []);

        $rows = new ResourceRows($resource);
        $blank = $query->getModel()->newInstance();

        // A resource may yield a field only for some rows (`when`, `whenLoaded`): keep reading, a chunk at a time,
        // until every column is seen or the discovery limit is reached.
        $layout = $rows->layoutUntil(
            self::batches($query),
            $blank,
            fn (array $layout): bool => self::unknownColumns($columns, $layout) === [],
        );
        $unknown = self::unknownColumns($columns, $layout);

        if ($unknown !== []) {
            throw ExportException::unknownColumns(array_map(
                fn (ExportColumn $c): string => implode('.', [...$c->groups, $c->key]),
                $unknown,
            ));
        }

        return array_map(
            fn (ExportColumn $c): ExportColumn => $c->withType(
                $declared[implode('.', [...$c->groups, $c->key])] ?? CellType::guess($c->key, self::layoutLevelOf($c, $layout)['values'][$c->key] ?? null),
            ),
            $columns,
        );
    }

    /**
     * A plain eager load is one Eloquent itself declared without constraining anything: Eloquent wraps every
     * constraint of a relation in a closure of its own that holds the originals, so each one is looked at. A closure
     * declared anywhere else, or one Eloquent built from a `relation:column` select, is a constraint.
     */
    private static function isPlainEagerLoad(Closure $constraint): bool
    {
        $function = new ReflectionFunction($constraint);

        if ($function->getFileName() !== (new ReflectionClass(Builder::class))->getFileName()) {
            return false;
        }

        $captured = $function->getStaticVariables();

        if ($captured === []) {
            return true;
        }

        if (array_keys($captured) !== ['constraints'] || ! is_array($captured['constraints'])) {
            return false;
        }

        foreach ($captured['constraints'] as $inner) {
            if (! $inner instanceof Closure || ! self::isPlainEagerLoad($inner)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The sample first, then chunks of the rest, up to the discovery limit. Chunks follow each other by offset, which
     * is stable because the query has an ORDER BY.
     *
     * @param  Builder<*>  $query
     * @return Generator<int, list<Model>>
     */
    private static function batches(Builder $query): Generator
    {
        $offset = 0;
        $size = self::SAMPLE_ROWS;

        while ($offset < self::DISCOVERY_ROWS) {
            $rows = self::sampleRows($query, $size, $offset);

            yield $rows;

            if (count($rows) < $size) {
                return;
            }

            $offset += $size;
            $size = min(self::DISCOVERY_CHUNK, self::DISCOVERY_ROWS - $offset);
        }
    }

    /**
     * @param  list<ExportColumn>  $columns
     * @param  array{values: array<string, scalar|null>, lists: array<string, array<string, mixed>>}  $layout
     * @return list<ExportColumn>
     */
    private static function unknownColumns(array $columns, array $layout): array
    {
        return array_values(array_filter($columns, fn (ExportColumn $c): bool => ! self::isColumnKnown($c, $layout)));
    }

    /**
     * `key => heading` and `list => {key => heading, ...}` into one ordered list; a list's columns carry its name in `groups`.
     *
     * @param  array<string, mixed>  $columns
     * @param  list<string>  $groups
     * @return list<ExportColumn>
     *
     * @throws ExportException
     */
    private static function flattenColumns(array $columns, array $groups): array
    {
        $result = [];

        foreach ($columns as $key => $value) {
            if (! is_array($value)) {
                $result[] = new ExportColumn((string) $key, (string) $value, null, $groups);

                continue;
            }

            if (count($groups) >= self::MAX_DEPTH) {
                throw ExportException::nestedTooDeep(self::MAX_DEPTH);
            }

            array_push($result, ...self::flattenColumns($value, [...$groups, (string) $key]));
        }

        return $result;
    }

    /**
     * `$limit` rows of the query as it was received, from `$offset` (its order and eager loads kept), read with lazy loading blocked
     * like the job reads them. The received query is not touched.
     *
     * @param  Builder<*>  $query
     * @return list<Model>
     */
    private static function sampleRows(Builder $query, int $limit, int $offset): array
    {
        $previous = Model::preventsLazyLoading();
        Model::preventLazyLoading();

        try {
            $rows = (clone $query)->offset($offset)->limit($limit)->get();
            // Eloquent only arms the lazy loading guard for result sets with more than one model; arm it for every row.
            $rows->each(fn (Model $m) => $m->preventsLazyLoading = true);

            return $rows->all();
        } finally {
            Model::preventLazyLoading($previous);
        }
    }

    /**
     * The part of the layout a column reads from: the document itself, or the items of its list, however deep.
     *
     * @param  array{values: array<string, scalar|null>, lists: array<string, array<string, mixed>>}  $layout
     * @return array{values: array<string, scalar|null>, lists: array<string, array<string, mixed>>}|null Null when the list is not in the resource.
     */
    private static function layoutLevelOf(ExportColumn $column, array $layout): ?array
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
     * @param  array{values: array<string, scalar|null>, lists: array<string, array<string, mixed>>}  $layout
     */
    private static function isColumnKnown(ExportColumn $column, array $layout): bool
    {
        // The list must be in the resource, and the column in the list's items. A list that no sampled document has
        // items in shows no keys: its columns are accepted untyped, and an item that does not yield one gets an empty cell.
        $level = self::layoutLevelOf($column, $layout);

        if ($level === null) {
            return false;
        }

        if ($column->groups !== [] && $level['values'] === [] && $level['lists'] === []) {
            return true;
        }

        return array_key_exists($column->key, $level['values']) || self::isBelowNullObject($column->key, $level['values']);
    }

    /**
     * `partner.name` cannot be seen when no sampled row has a partner: the resource renders `partner` as null. A key
     * below such a null object is accepted untyped; the resource decides what it holds when a row has one.
     *
     * @param  array<string, scalar|null>  $seen
     */
    private static function isBelowNullObject(string $key, array $seen): bool
    {
        while (($dot = strrpos($key, '.')) !== false) {
            $key = substr($key, 0, $dot);

            if (array_key_exists($key, $seen)) {
                return $seen[$key] === null;
            }
        }

        return false;
    }
}
