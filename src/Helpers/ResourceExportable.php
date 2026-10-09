<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Alif\Export\Contracts\Exportable;
use Alif\QueryFilter\Interfaces\EBFilterInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;
use WeakMap;

/**
 * One generic Exportable for any model that already has a filter and an API resource: columns are the
 * resource's flattened output (`business_partner.name`), the children are its HasMany list (`products`).
 * Nothing is declared per document; the column set is discovered from a sample of rows.
 *
 * The base query comes from the host ({@see query()}), so what the host's own list applies (default scopes,
 * aggregates the resource reads) applies to the export too. Every row is resolved through the resource once and
 * cached while the row is alive, so the resource (and the relations it touches, which `$with` must eager load)
 * is the single source of values.
 *
 * Discovery is deterministic (rows ordered by key, plus a blank model so nested objects that are null in every
 * row still contribute their keys) and its result, the key list, is cached so the request that validates the
 * columns and the worker that writes them see the same definition. Labels are rebuilt per call: they follow the
 * locale of the caller.
 */
final class ResourceExportable implements Exportable
{
    /** Keys ending like this are numeric when their value is a numeric string (decimals come as strings). */
    private const string NUMERIC_KEY = '/(sum|price|quantity|amount|total|rate|cost|balance|discount)$/';

    private const int SAMPLE_ROWS = 20;

    /** Seconds a discovered column set is shared between requests and workers. */
    private const int LAYOUT_TTL = 600;

    /** @var WeakMap<Model, array<string, scalar|null>> */
    private WeakMap $values;

    /**
     * @param  class-string<Model>  $model
     * @param  class-string<JsonResource>  $resource
     * @param  Closure(array<string, mixed>): EBFilterInterface  $filter
     * @param  Closure(array<string, mixed>): Builder  $query  The document's base query for the given parameters.
     * @param  array<int|string, string|Closure>  $with  Relations the resource reads (Eloquent `with()` shapes); every column declares them.
     * @param  string|null  $childRelation  HasMany relation exported as children; null detects the first HasMany list in the resource.
     * @param  list<string>  $numeric  Keys always written as numbers.
     */
    public function __construct(
        private readonly string $title,
        private readonly string $model,
        private readonly string $resource,
        private readonly Closure $filter,
        private readonly Closure $query,
        private readonly array $with = [],
        private readonly ?string $childRelation = null,
        private readonly array $numeric = [],
    ) {
        $this->values = new WeakMap;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function columns(): array
    {
        $layout = $this->layout();

        return $this->describe($layout['columns'], $layout['child'], $this->with, null);
    }

    public function childRelation(): ?string
    {
        return $this->layout()['child'];
    }

    public function childColumns(): array
    {
        $layout = $this->layout();

        if ($layout['child'] === null) {
            return [];
        }

        $taken = array_values(array_map(fn (Column $column): string => $column->label(), $this->describe($layout['columns'], $layout['child'], [], null)));

        return $this->describe($layout['child_columns'], $layout['child'], [], $taken);
    }

    public function query(array $parameters): Builder
    {
        return ($this->query)($parameters);
    }

    public function filter(array $parameters): EBFilterInterface
    {
        return ($this->filter)($parameters);
    }

    /**
     * The discovered keys, shared through the cache.
     *
     * @return array{columns: array<string, bool>, child_columns: array<string, bool>, child: ?string}
     */
    private function layout(): array
    {
        return Cache::remember($this->layoutKey(), self::LAYOUT_TTL, fn (): array => $this->discover());
    }

    private function layoutKey(): string
    {
        $with = array_map(fn (int|string $name, string|Closure $value): string => is_int($name) ? (string) $value : $name, array_keys($this->with), $this->with);

        return 'export-layout:'.md5(implode('|', [self::class, $this->title, $this->model, $this->resource, (string) $this->childRelation, implode(',', $with), implode(',', $this->numeric)]));
    }

    /** @return array{columns: array<string, bool>, child_columns: array<string, bool>, child: ?string} */
    private function discover(): array
    {
        $rows = $this->query([])->with($this->with)->reorder()->orderBy((new $this->model)->getQualifiedKeyName())->limit(self::SAMPLE_ROWS)->get();
        $blank = new $this->model;
        $child = $this->childRelation ?? $this->detectChild($rows->first() ?? $blank);
        $childKey = $child === null ? null : Str::snake($child);

        $parents = [];
        $children = [];

        foreach ([...$rows->all(), $blank] as $row) {
            $payload = $this->payload($row);
            $parents = $this->merge($parents, $this->flatten($payload));

            foreach ($childKey === null ? [] : (array) ($payload[$childKey] ?? []) as $item) {
                $children = $this->merge($children, $this->flatten((array) $item));
            }
        }

        // An empty table and a resource that cannot render a blank model: fall back to the table's columns.
        if ($parents === []) {
            $parents = $this->tableColumns();
        }

        return [
            'columns' => $this->numericFlags($this->withoutShadowed($parents), $childKey),
            'child_columns' => $this->numericFlags($this->withoutShadowed($children), null),
            'child' => $child,
        ];
    }

    /**
     * @param  array<string, bool>  $keys
     * @param  list<string>|null  $taken  Document headings a child heading must not repeat.
     * @return array<string, Column>
     */
    private function describe(array $keys, ?string $child, array $relations, ?array $taken): array
    {
        $columns = [];

        foreach ($keys as $key => $numeric) {
            $label = $this->label($key);
            // A child heading equal to a document heading ("Total Sum") is prefixed with the child's name.
            if ($taken !== null && $child !== null && in_array($label, $taken, true)) {
                $label = Str::headline(Str::singular($child)).' '.$label;
            }

            $column = Column::make($label, fn (Model $row): mixed => $this->read($row, $key, $child))->numeric($numeric);

            // Any selection must load what the resource touches, so every column declares the relations.
            $columns[$key] = $relations === [] ? $column : $column->relations($relations);
        }

        return $columns;
    }

    /**
     * @param  array<string, scalar|null>  $sample
     * @return array<string, bool> Key => written as a number.
     */
    private function numericFlags(array $sample, ?string $skipPrefix): array
    {
        $flags = [];

        foreach ($sample as $key => $value) {
            if ($skipPrefix !== null && ($key === $skipPrefix || str_starts_with($key, $skipPrefix.'.'))) {
                continue;
            }

            $flags[$key] = in_array($key, $this->numeric, true) || $this->looksNumeric($key, $value);
        }

        return $flags;
    }

    /**
     * A key that is null in one row but an object in another appears both as `business_partner` and as
     * `business_partner.name`; only the nested keys are columns.
     *
     * @param  array<string, scalar|null>  $flat
     * @return array<string, scalar|null>
     */
    private function withoutShadowed(array $flat): array
    {
        return array_filter(
            $flat,
            fn (string $key): bool => array_filter(array_keys($flat), fn (string $other): bool => str_starts_with($other, $key.'.')) === [],
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** @return array<string, null> */
    private function tableColumns(): array
    {
        try {
            $model = new $this->model;
            $names = Schema::connection($model->getConnectionName())->getColumnListing($model->getTable());
        } catch (Throwable) {
            return [];
        }

        return array_fill_keys(array_values(array_diff($names, (new $this->model)->getHidden())), null);
    }

    /** Absent from the row (a column of an older discovery): an empty cell, never an error. */
    private function read(Model $row, string $key, ?string $child): mixed
    {
        return ($this->values[$row] ??= $this->resolve($row, $child))[$key] ?? null;
    }

    /** @return array<string, scalar|null> */
    private function resolve(Model $row, ?string $child): array
    {
        $payload = $this->payload($row);

        // Children come from the parent's payload, in the order of the loaded relation.
        if ($child !== null && $row->relationLoaded($child)) {
            foreach ($row->getRelation($child)->values() as $i => $model) {
                $this->values[$model] = $this->flatten((array) ($payload[Str::snake($child)][$i] ?? []));
            }
        }

        return $this->flatten($payload);
    }

    /**
     * A resource that cannot render a row (a blank model during discovery) yields nothing instead of failing.
     *
     * @return array<string, mixed>
     */
    private function payload(Model $row): array
    {
        try {
            // The JSON round trip turns nested resources and collections into plain arrays.
            return json_decode((string) json_encode((new ($this->resource)($row))->resolve(request())), true) ?? [];
        } catch (Throwable $e) {
            if ($row->exists) {
                throw $e;
            }

            return [];
        }
    }

    /**
     * Objects recurse into dotted keys; lists are dropped (children are handled separately).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, scalar|null>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $path = $prefix.$key;

            if (is_array($value)) {
                if (! array_is_list($value)) {
                    $flat += $this->flatten($value, $path.'.');
                }

                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }

    /**
     * @param  array<string, scalar|null>  $into
     * @param  array<string, scalar|null>  $from
     * @return array<string, scalar|null>
     */
    private function merge(array $into, array $from): array
    {
        foreach ($from as $key => $value) {
            $into[$key] = $into[$key] ?? $value;
        }

        return $into;
    }

    private function detectChild(Model $sample): ?string
    {
        foreach (array_keys($this->payload($sample)) as $key) {
            $relation = Str::camel((string) $key);

            if (method_exists($sample, $relation) && $sample->{$relation}() instanceof HasMany) {
                return $relation;
            }
        }

        return null;
    }

    private function looksNumeric(string $key, mixed $value): bool
    {
        return is_int($value)
            || is_float($value)
            || (is_string($value) && is_numeric($value) && preg_match(self::NUMERIC_KEY, $key) === 1);
    }

    /** Top-level keys use the attribute translation; nested ones read "Business Partner Name", never a bare "name". */
    private function label(string $key): string
    {
        if (! str_contains($key, '.') && Lang::has("validation.attributes.$key")) {
            return (string) __("validation.attributes.$key");
        }

        return Str::headline(str_replace('.', ' ', $key));
    }
}
