<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Alif\Export\DTO\Export\ExportColumn;
use Alif\Export\DTO\Export\ExportDTO;
use Alif\Export\Enums\ColumnTypeEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Checks the requested columns against what the filter's resource really yields, and gives each column its type.
 * It renders a few rows of the list through the resource (a "sample") and looks the columns up in what came out.
 */
final class ColumnResolver
{
    private const int SAMPLE_ROWS = 20;

    /**
     * @param  Builder<*>  $query  The export's query ({@see ExportQuery::wholeSet()}).
     * @return list<ExportColumn> The requested columns, each with its type.
     *
     * @throws ValidationException A column the resource does not yield.
     */
    public function resolve(ExportDTO $request, Builder $query): array
    {
        $source = $request->source::exportSource();
        $rows = (clone $query)->reorder()->orderBy($query->getModel()->getQualifiedKeyName())->limit(self::SAMPLE_ROWS)->with($source['with'])->get();
        $layout = (new ResourceRows($source['resource']))->layout($rows->all(), new $source['model']);

        $unknown = array_filter($request->columns, fn (ExportColumn $c): bool => ! $this->isKnown($c, $layout));

        if ($unknown !== []) {
            throw ValidationException::withMessages(['columns' => array_values(array_map(
                fn (ExportColumn $c): string => __('export::messages.unknown_column', ['column' => implode('.', [...$c->groups, $c->key])]),
                $unknown,
            ))]);
        }

        return array_map(
            fn (ExportColumn $c): ExportColumn => $c->withType(ColumnTypeEnum::guess($c->key, $this->levelOf($c, $layout)['values'][$c->key] ?? null)),
            $request->columns,
        );
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
        $level = $this->levelOf($column, $layout);

        if ($level === null) {
            return false;
        }

        // No sampled document had items in this list, so there are no keys to check: the list itself exists, its keys are accepted untyped.
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
