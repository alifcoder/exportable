<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Alif\Export\Enums\ColumnType;
use Closure;
use Illuminate\Database\Eloquent\Model;

final class Column
{
    /** @var array<string, Closure|null> Relation name => constraint (null: plain eager load). */
    private array $relations = [];

    private ?ColumnType $type = null;

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

    /** Write the cells as this type; without one each cell keeps the type of its value. */
    public function as(ColumnType $type): self
    {
        $this->type = $type;

        return $this;
    }

    /** A number, whole or decimal by the value of each cell. */
    public function numeric(bool $numeric = true): self
    {
        $this->type = $numeric ? ColumnType::Number : null;

        return $this;
    }

    /** A date without time (`export.style.date_format`). */
    public function date(bool $date = true): self
    {
        $this->type = $date ? ColumnType::Date : null;

        return $this;
    }

    public function type(): ?ColumnType
    {
        return $this->type;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function isNumeric(): bool
    {
        return $this->type?->isNumeric() ?? false;
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
