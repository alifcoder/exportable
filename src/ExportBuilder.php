<?php

declare(strict_types=1);

namespace Alif\Export;

use Alif\Export\Contracts\Exportable;
use Alif\Export\Enums\ExportFormat;
use BackedEnum;
use Carbon\CarbonInterface;
use Closure;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

final class ExportBuilder
{
    /** Apply the host filter, then own eager loading and ordering. */
    public function query(Exportable $exportable, ExportOptions $options): Builder
    {
        $builder = $exportable->query();
        $this->assertChildRelation($exportable, $options, $builder);
        $exportable->filter($options->parameters)->apply($builder);

        $builder->setEagerLoads([]);
        $builder->with($this->eagerLoads($exportable, $options));
        $builder->orderBy($builder->getModel()->getQualifiedKeyName());

        return $builder;
    }

    /** @throws ValidationException */
    public function assertWithinCap(Builder $query, ExportFormat $format): void
    {
        $cap = $format->maxRows();

        if ((clone $query)->reorder()->offset($cap)->limit(1)->exists()) {
            throw ValidationException::withMessages([
                'file.format' => [sprintf('Too many rows for %s export (maximum %d).', $format->value, $cap)],
            ]);
        }
    }

    /** @return list<string> */
    public function headings(Exportable $exportable, ExportOptions $options): array
    {
        return array_map(
            fn (Column $column): string => (string) __($column->label()),
            $this->selectedColumns($exportable, $options),
        );
    }

    /** @return list<bool> */
    public function numericMap(Exportable $exportable, ExportOptions $options): array
    {
        return array_map(
            fn (Column $column): bool => $column->isNumeric(),
            $this->selectedColumns($exportable, $options),
        );
    }

    /**
     * @return Generator<int, list<string|int|float|null>>
     *
     * @throws ExportException
     */
    public function rows(Exportable $exportable, ExportOptions $options, Builder $query): Generator
    {
        $cap = $options->format->maxRows();
        $childRelation = $options->includeChildren ? $exportable->childRelation() : null;
        $childColumns = $childRelation === null ? [] : $this->pick($exportable->childColumns(), $options->childColumns);
        $blankChild = array_fill(0, count($childColumns), null);
        $columns = $this->pick($exportable->columns(), $options->columns);
        $emitted = 0;

        foreach ($query->lazy((int) config('export.chunk_size', 500)) as $row) {
            // Eloquent only arms the guard for result sets with more than one model; arm it for every row.
            $row->preventsLazyLoading = Model::preventsLazyLoading();
            $base = $this->values($columns, $row);

            $children = $childRelation === null ? [] : $row->getRelation($childRelation)->all();

            $lines = $childRelation === null
                ? [$base]
                : ($children === []
                    ? [[...$base, ...$blankChild]]
                    : array_map(function (Model $child) use ($base, $childColumns): array {
                        $child->preventsLazyLoading = Model::preventsLazyLoading();

                        return [...$base, ...$this->values($childColumns, $child)];
                    }, $children));

            foreach ($lines as $line) {
                if (++$emitted > $cap) {
                    throw ExportException::rowLimitExceeded($cap);
                }

                yield $line;
            }
        }
    }

    /** @param Builder<Model> $builder */
    private function assertChildRelation(Exportable $exportable, ExportOptions $options, Builder $builder): void
    {
        $relation = $options->includeChildren ? $exportable->childRelation() : null;

        if ($relation === null) {
            return;
        }

        $model = $builder->getModel();

        if (! method_exists($model, $relation) || ! $model->{$relation}() instanceof HasMany) {
            throw ExportException::invalidRegistration(sprintf(
                'Child relation "%s" on %s must be a HasMany relation.',
                $relation,
                $model::class,
            ));
        }
    }

    /** @return array<int|string, string|Closure> */
    private function eagerLoads(Exportable $exportable, ExportOptions $options): array
    {
        $loads = [];

        foreach ($this->pick($exportable->columns(), $options->columns) as $column) {
            foreach ($column->getRelations() as $relation) {
                $loads[$relation] = $loads[$relation] ?? null;
            }
        }

        $childRelation = $options->includeChildren ? $exportable->childRelation() : null;

        if ($childRelation !== null) {
            $loads[$childRelation] = fn ($query) => $query->orderBy($query->getModel()->getQualifiedKeyName());

            foreach ($this->pick($exportable->childColumns(), $options->childColumns) as $column) {
                foreach ($column->getRelations() as $relation) {
                    $loads["{$childRelation}.{$relation}"] = $loads["{$childRelation}.{$relation}"] ?? null;
                }
            }
        }

        $with = [];
        foreach ($loads as $name => $constraint) {
            if ($constraint === null) {
                $with[] = $name;
            } else {
                $with[$name] = $constraint;
            }
        }

        return $with;
    }

    /** @return list<Column> */
    private function selectedColumns(Exportable $exportable, ExportOptions $options): array
    {
        $columns = $this->pick($exportable->columns(), $options->columns);

        if ($options->includeChildren && $exportable->childRelation() !== null) {
            $columns = [...$columns, ...$this->pick($exportable->childColumns(), $options->childColumns)];
        }

        return array_values($columns);
    }

    /**
     * @param  array<string, Column>  $available
     * @param  list<string>  $keys
     * @return array<string, Column>
     */
    private function pick(array $available, array $keys): array
    {
        $picked = [];

        foreach ($keys as $key) {
            $picked[$key] = $available[$key];
        }

        return $picked;
    }

    /**
     * @param  array<string, Column>  $columns
     * @return list<string|int|float|null>
     */
    private function values(array $columns, Model $model): array
    {
        $values = [];

        foreach ($columns as $key => $column) {
            $values[] = $this->normalize($column->resolve($model, $key), $key);
        }

        return $values;
    }

    private function normalize(mixed $value, string $key): string|int|float|null
    {
        return match (true) {
            $value === null => null,
            $value instanceof BackedEnum => $value->value,
            $value instanceof CarbonInterface => $value->format('Y-m-d H:i:s'),
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => $value,
            default => throw ExportException::invalidColumnValue($key),
        };
    }
}
