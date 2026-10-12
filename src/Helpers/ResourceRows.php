<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Alif\Export\Enums\ColumnType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads a model the way the API shows it: through its resource, flattened into dotted keys
 * (`business_partner.name`). The resource is the single source of values, so the relations it touches must be
 * eager loaded. A child list (`products`) is taken from the same payload, in the resource's own order.
 */
final class ResourceRows
{
    /** Keys ending like this are numeric when their value is a numeric string (decimals come as strings). */
    private const string NUMERIC_KEY = '/(sum|price|quantity|amount|total|rate|cost|balance|discount)$/';

    /** An ISO 8601 date-time as Laravel serialises a date; a date alone. */
    private const string DATETIME_VALUE = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/';

    private const string DATE_VALUE = '/^\d{4}-\d{2}-\d{2}$/';

    /** @param class-string<JsonResource> $resource */
    public function __construct(private readonly string $resource, private readonly ?string $childRelation = null) {}

    /**
     * @return array{0: array<string, scalar|null>, 1: list<array<string, scalar|null>>} The row's values and its children's.
     */
    public function split(Model $row): array
    {
        return $this->divide($this->payload($row, false));
    }

    /**
     * The keys a resource yields, with the first non-null value seen for each. Nested objects that are null in every
     * sampled row still contribute their keys through the blank model.
     *
     * @param  iterable<Model>  $rows
     * @return array{0: array<string, scalar|null>, 1: array<string, scalar|null>} Parent keys, child keys.
     */
    public function layout(iterable $rows, Model $blank): array
    {
        $parents = [];
        $children = [];

        foreach ([...$rows, $blank] as $row) {
            [$parent, $items] = $this->divide($this->payload($row, ! $row->exists));
            $parents = $this->merge($parents, $parent);

            foreach ($items as $item) {
                $children = $this->merge($children, $item);
            }
        }

        return [$this->withoutShadowed($parents), $this->withoutShadowed($children)];
    }

    /**
     * A resource renders through JSON, so a date arrives as text and a decimal as a numeric string: those are
     * recognised by their shape (and, for decimals, by the key), the rest by the value's own type.
     */
    public static function typeOf(string $key, mixed $value): ?ColumnType
    {
        return match (true) {
            is_bool($value) => ColumnType::Boolean,
            is_int($value), is_float($value) => ColumnType::Number,
            ! is_string($value) => null,
            preg_match(self::DATETIME_VALUE, $value) === 1 => ColumnType::DateTime,
            preg_match(self::DATE_VALUE, $value) === 1 => ColumnType::Date,
            is_numeric($value) && preg_match(self::NUMERIC_KEY, $key) === 1 => ColumnType::Number,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: array<string, scalar|null>, 1: list<array<string, scalar|null>>}
     */
    private function divide(array $payload): array
    {
        $children = [];

        if ($this->childRelation !== null) {
            foreach ((array) ($payload[Str::snake($this->childRelation)] ?? []) as $item) {
                $children[] = $this->flatten((array) $item);
            }
        }

        return [$this->flatten($payload), $children];
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
