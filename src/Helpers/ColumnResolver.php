<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Alif\Export\DTO\Export\ExportColumn;
use Alif\Export\DTO\Export\ExportDTO;
use Alif\Export\Exceptions\ExportException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Checks the requested columns against what the export's resource really yields, and gives each column its type.
 * It renders a few rows of the received query (with its own eager loads) through the resource, a "sample", and looks the
 * columns up in what came out.
 */
final class ColumnResolver
{
    private const int SAMPLE_ROWS = 20;

    /**
     * @param  Builder<*>  $query  The filter's query, read as given.
     * @param  class-string<JsonResource>  $resource
     * @return list<ExportColumn> The requested columns, each with its type.
     *
     * @throws ExportException A document column the resource does not yield, or a resource that fails.
     */
    public function resolve(ExportDTO $request, Builder $query, string $resource): array
    {
        $layout = (new ResourceRows($resource))->layout($this->sample($query), $query->getModel()->newInstance());

        $unknown = array_filter($request->columns, fn (ExportColumn $c): bool => ! $this->isKnown($c, $layout));

        if ($unknown !== []) {
            throw ExportException::unknownColumns(array_values(array_map(
                fn (ExportColumn $c): string => implode('.', [...$c->groups, $c->key]),
                $unknown,
            )));
        }

        return array_map(
            fn (ExportColumn $c): ExportColumn => $c->withType($c->type ?? CellType::guess($c->key, $this->levelOf($c, $layout)['values'][$c->key] ?? null)),
            $request->columns,
        );
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
        // A list item column is tried and written empty when the item does not yield it; the filter has already checked
        // that the list and the column are offered. Only a document column must exist in the resource.
        if ($column->groups !== []) {
            return true;
        }

        $level = $this->levelOf($column, $layout);

        return $level !== null && (array_key_exists($column->key, $level['values']) || $this->isUnderNullObject($column->key, $level['values']));
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
