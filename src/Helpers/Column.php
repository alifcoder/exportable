<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Closure;
use Illuminate\Database\Eloquent\Model;

final class Column
{
    /** @var array<string, Closure|null> Relation name => constraint (null: plain eager load). */
    private array $relations = [];

    private bool $numeric = false;

    private bool $date = false;

    private function __construct(private readonly string $label, private readonly ?Closure $value) {}

    /** @param Closure|null $value fn (YourModel $row): mixed. Null reads data_get($row, $key). */
    public static function make(string $label, ?Closure $value = null): self
    {
        return new self($label, $value);
    }

    /**
     * Declare the relations this column needs eager loaded. Accepts Eloquent's `with()` shapes: plain names, or
     * `name => Closure` for a constrained load; named plain loads such as `'c.d'` are kept as they are.
     *
     * @param  string|array<int|string, string|Closure>  ...$relations
     */
    public function relations(string|array ...$relations): self
    {
        foreach ($relations as $group) {
            foreach ((array) $group as $name => $constraint) {
                [$name, $constraint] = is_int($name) ? [$constraint, null] : [$name, $constraint];
                $this->relations[$name] ??= $constraint;
            }
        }

        return $this;
    }

    public function numeric(bool $numeric = true): self
    {
        $this->numeric = $numeric;

        return $this;
    }

    /** Format a date/time value with `export.style.date_format` (no time) instead of the date-time format. */
    public function date(bool $date = true): self
    {
        $this->date = $date;

        return $this;
    }

    public function isDate(): bool
    {
        return $this->date;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function isNumeric(): bool
    {
        return $this->numeric;
    }

    /** @return array<string, Closure|null> */
    public function getRelations(): array
    {
        return $this->relations;
    }

    public function resolve(Model $row, string $key): mixed
    {
        return $this->value === null ? data_get($row, $key) : ($this->value)($row);
    }
}
