<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Closure;
use Illuminate\Database\Eloquent\Model;

final class Column
{
    /** @var list<string> */
    private array $relations = [];

    private bool $numeric = false;

    private function __construct(private readonly string $label, private readonly ?Closure $value) {}

    /** @param Closure|null $value fn (YourModel $row): mixed. Null reads data_get($row, $key). */
    public static function make(string $label, ?Closure $value = null): self
    {
        return new self($label, $value);
    }

    /** Declare the relations this column needs eager loaded. */
    public function relations(string ...$relations): self
    {
        $this->relations = array_values(array_unique([...$this->relations, ...$relations]));

        return $this;
    }

    public function numeric(bool $numeric = true): self
    {
        $this->numeric = $numeric;

        return $this;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function isNumeric(): bool
    {
        return $this->numeric;
    }

    /** @return list<string> */
    public function getRelations(): array
    {
        return $this->relations;
    }

    public function resolve(Model $row, string $key): mixed
    {
        return $this->value === null ? data_get($row, $key) : ($this->value)($row);
    }
}
