<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Alif\Export\DTO\Export\ExportColumn;
use Alif\Export\DTO\Export\ExportDTO;
use Alif\Export\Exceptions\ExportException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The one place that validates the requested `columns`. It flattens the nested lists into ordered {@see ExportColumn}s,
 * refuses a column ending in `_id`, limits the depth of lists, wants one column of the document itself, and checks the
 * rest against what the export's resource really yields: it renders a few rows of the received query (with its own eager
 * loads) through the resource, a "sample", and looks the columns up in what came out. Each column gets its type.
 */
final class ColumnResolver
{
    private const int SAMPLE_ROWS = 20;

    /** Lists inside lists, counted from the document. */
    private const int MAX_DEPTH = 5;

    /**
     * @return list<ExportColumn> The requested columns in the order sent, each with its type.
     *
     * @throws ExportException A column that cannot be exported or the resource does not yield, or a resource that fails.
     */
    public function resolve(ExportDTO $request): array
    {
        $query = $request->query;
        $columns = $this->flatten($request->columns, []);

        $this->assertExportable($columns);

        $layout = (new ResourceRows($request->resource))->layout($this->sample($query), $query->getModel()->newInstance());

        $unknown = array_filter($columns, fn (ExportColumn $c): bool => ! $this->isKnown($c, $layout));

        if ($unknown !== []) {
            throw ExportException::unknownColumns(array_values(array_map(
                fn (ExportColumn $c): string => implode('.', [...$c->groups, $c->key]),
                $unknown,
            )));
        }

        return array_map(
            fn (ExportColumn $c): ExportColumn => $c->withType(CellType::guess($c->key, $this->levelOf($c, $layout)['values'][$c->key] ?? null)),
            $columns,
        );
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
    private function flatten(array $columns, array $groups): array
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

            array_push($result, ...$this->flatten($value, [...$groups, (string) $key]));
        }

        return $result;
    }

    /**
     * @param  list<ExportColumn>  $columns
     *
     * @throws ExportException
     */
    private function assertExportable(array $columns): void
    {
        if (array_filter($columns, fn (ExportColumn $c): bool => $c->groups === []) === []) {
            throw ExportException::needsDocumentColumn();
        }

        $refused = [];

        foreach ($columns as $c) {
            if (str_ends_with(Str::afterLast($c->key, '.'), '_id')) {
                $path = implode('.', [...$c->groups, $c->key]);
                $refused[] = (string) __('export::messages.column_not_exportable', ['column' => $path]);
            }
        }

        if ($refused !== []) {
            throw ExportException::columnsRefused($refused);
        }
    }

    /**
     * The first rows of the query as it was received (its order and eager loads kept), read with lazy loading blocked
     * like the job reads them. The received query is not touched.
     *
     * @param  Builder<*>  $query
     * @return list<Model>
     */
    private function sample(Builder $query): array
    {
        $previous = Model::preventsLazyLoading();
        Model::preventLazyLoading();

        try {
            $rows = (clone $query)->limit(self::SAMPLE_ROWS)->get();
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
     * @param  array{values: array<string, scalar|null>, lists: array<string, array<string, mixed>>}  $layout
     */
    private function isKnown(ExportColumn $column, array $layout): bool
    {
        // The list must be in the resource, and the column in the list's items. A list that no sampled document has
        // items in shows no keys: its columns are accepted untyped, and an item that does not yield one gets an empty cell.
        $level = $this->levelOf($column, $layout);

        if ($level === null) {
            return false;
        }

        if ($column->groups !== [] && $level['values'] === [] && $level['lists'] === []) {
            return true;
        }

        return array_key_exists($column->key, $level['values']) || $this->isUnderNullObject($column->key, $level['values']);
    }

    /**
     * `partner.name` cannot be seen when no sampled row has a partner: the resource renders `partner` as null. A key
     * below such a null object is accepted untyped; the resource decides what it holds when a row has one.
     *
     * @param  array<string, scalar|null>  $seen
     */
    private function isUnderNullObject(string $key, array $seen): bool
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
