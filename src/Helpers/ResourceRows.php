<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Alif\Export\Exceptions\ExportException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Throwable;

/**
 * Reads a model the way the API shows it: through its resource, flattened into dotted keys (`business_partner.name`).
 * A list the resource renders (`products`) is kept apart, one flat map per item. The resource is the single source of
 * values, so the relations it touches must be eager loaded.
 *
 * A resource that throws while rendering a real row fails with {@see ExportException::resourceFailed()}.
 */
final class ResourceRows
{
    /** @param class-string<JsonResource> $resource */
    public function __construct(private readonly string $resource) {}

    /**
     * The row's values by dotted key, and each list the resource renders (`products`) as its items, each item read the same way.
     *
     * @return array{values: array<string, scalar|null>, lists: array<string, list<array<string, mixed>>>}
     */
    public function read(Model $row): array
    {
        return $this->split($this->payload($row, false));
    }

    /**
     * The keys a resource yields, with the first non-null value seen for each. Nested objects that are null in every
     * sampled row still contribute their keys through the blank model. A list contributes the keys of its items, and
     * so do the lists inside those.
     *
     * @param  iterable<Model>  $rows
     * @return array{values: array<string, scalar|null>, lists: array<string, array<string, mixed>>}
     */
    public function layout(iterable $rows, Model $blank): array
    {
        $layout = ['values' => [], 'lists' => []];

        foreach ([...$rows, $blank] as $row) {
            $layout = $this->absorb($layout, $this->split($this->payload($row, ! $row->exists)));
        }

        return $this->unshadowed($layout);
    }

    /**
     * Adds one row's keys and values to the layout. A value that is already set is kept.
     *
     * @param  array{values: array<string, scalar|null>, lists: array<string, array<string, mixed>>}  $layout
     * @param  array{values: array<string, scalar|null>, lists: array<string, list<array<string, mixed>>>}  $read
     * @return array{values: array<string, scalar|null>, lists: array<string, array<string, mixed>>}
     */
    private function absorb(array $layout, array $read): array
    {
        $layout['values'] = $this->merge($layout['values'], $read['values']);

        foreach ($read['lists'] as $path => $items) {
            $layout['lists'][$path] ??= ['values' => [], 'lists' => []];

            foreach ($items as $item) {
                $layout['lists'][$path] = $this->absorb($layout['lists'][$path], $item);
            }
        }

        return $layout;
    }

    /**
     * Removes the shadowed keys from the layout, in the lists too.
     *
     * @param  array{values: array<string, scalar|null>, lists: array<string, array<string, mixed>>}  $layout
     * @return array{values: array<string, scalar|null>, lists: array<string, array<string, mixed>>}
     */
    private function unshadowed(array $layout): array
    {
        return ['values' => $this->withoutShadowed($layout['values']), 'lists' => array_map($this->unshadowed(...), $layout['lists'])];
    }

    /**
     * A blank model (discovery only) that the resource cannot render yields nothing instead of failing.
     *
     * @return array<string, mixed>
     */
    private function payload(Model $row, bool $lenient): array
    {
        try {
            // The JSON round trip turns nested resources and collections into plain arrays.
            return json_decode((string) json_encode((new ($this->resource)($row))->resolve(request())), true) ?? [];
        } catch (Throwable $e) {
            if (! $lenient) {
                throw $e instanceof ExportException ? $e : ExportException::resourceFailed($this->resource, $e);
            }

            return [];
        }
    }

    /**
     * Splits a resource's output into plain values and lists.
     *
     * @param  array<string, mixed>  $payload
     * @return array{values: array<string, scalar|null>, lists: array<string, list<array<string, mixed>>>}
     */
    private function split(array $payload): array
    {
        $lists = [];
        $values = $this->flatten($payload, '', $lists);

        return ['values' => $values, 'lists' => $lists];
    }

    /**
     * Objects recurse into dotted keys. A list of objects (or an empty list) is collected into `$lists` under its
     * dotted key, each item split the same way; a list of scalars is dropped.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, list<array<string, mixed>>>  $lists
     * @return array<string, scalar|null>
     */
    private function flatten(array $data, string $prefix, array &$lists): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $path = $prefix.$key;

            if (is_array($value)) {
                if (! array_is_list($value)) {
                    $flat += $this->flatten($value, $path.'.', $lists);
                } elseif (array_filter($value, fn (mixed $item): bool => ! is_array($item) || array_is_list($item)) === []) {
                    $lists[$path] = array_map($this->split(...), $value);
                }

                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }

    /**
     * Adds the new keys. For a key that exists, the first value that is not null stays.
     *
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
}
