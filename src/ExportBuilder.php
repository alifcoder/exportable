<?php

declare(strict_types=1);

namespace Alif\Export;

use BackedEnum;
use Carbon\CarbonInterface;
use Closure;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ExportBuilder
{
    /** Apply the host filter, then own eager loading and ordering. */
    public function query(ExportPlan $plan): Builder
    {
        $builder = $plan->exportable->query();
        $this->assertChildRelation($plan, $builder);
        $plan->exportable->filter($plan->options->parameters)->apply($builder);

        $builder->setEagerLoads([]);
        $builder->with($this->eagerLoads($plan));
        $builder->orderBy($builder->getModel()->getQualifiedKeyName());

        return $builder;
    }

    /** @throws ValidationException */
    public function assertWithinCap(ExportPlan $plan, Builder $query): void
    {
        $cap = $plan->options->format->maxRows();

        // With children the output row count is at least the document count, so one count query covers both.
        $exceeds = $plan->childRelation === null
            ? (clone $query)->reorder()->offset($cap)->limit(1)->exists()
            : $this->outputRows($query, $plan->childRelation) > $cap;

        if ($exceeds) {
            throw ValidationException::withMessages([
                'file.format' => [sprintf('Too many rows for %s export (maximum %d).', $plan->options->format->value, $cap)],
            ]);
        }
    }

    /**
     * @return Generator<int, list<string|int|float|null>>
     *
     * @throws ExportException
     */
    public function rows(ExportPlan $plan, Builder $query): Generator
    {
        $cap = $plan->options->format->maxRows();
        $blankChild = array_fill(0, count($plan->childColumns), null);
        $emitted = 0;

        foreach ($this->chunks($query) as $row) {
            // Eloquent only arms the guard for result sets with more than one model; arm it for every row.
            $row->preventsLazyLoading = Model::preventsLazyLoading();
            $base = $this->values($plan->columns, $row);

            foreach ($this->lines($plan, $row, $base, $blankChild) as $line) {
                if (++$emitted > $cap) {
                    throw ExportException::rowLimitExceeded($cap);
                }

                yield $line;
            }
        }
    }

    /**
     * Snapshot the ordered keys first, then load the models by key. Offset paging would skip or repeat rows when
     * data changes during a long export; this keeps the filter's sort and a stable set. Rows deleted meanwhile
     * are skipped. Only keys are held in memory (bounded by the format's row cap); the filter's limit/offset
     * applies to the key snapshot, and duplicate keys from joins are collapsed.
     *
     * @param  Builder<Model>  $query
     * @return Generator<int, Model>
     */
    private function chunks(Builder $query): Generator
    {
        $keyName = $query->getModel()->getKeyName();
        $keys = [];

        foreach ((clone $query)->setEagerLoads([])->select($query->getModel()->getQualifiedKeyName())->toBase()->cursor() as $record) {
            $keys[(string) $record->{$keyName}] ??= $record->{$keyName};
        }

        foreach (array_chunk(array_values($keys), max(1, (int) config('export.chunk_size', 500))) as $chunk) {
            $load = (clone $query)->reorder()->whereKey($chunk);
            // The snapshot already applied the filter's window; paging it again would truncate the chunk.
            $load->getQuery()->limit = null;
            $load->getQuery()->offset = null;
            $models = $load->get()->keyBy(fn (Model $m): string => (string) $m->getKey());

            foreach ($chunk as $key) {
                if (($model = $models->get((string) $key)) !== null) {
                    yield $model;
                }
            }
        }
    }

    /**
     * One line per child with the document cells repeated, or a single line for a childless document.
     *
     * @param  list<string|int|float|null>  $base
     * @param  list<null>  $blankChild
     * @return list<list<string|int|float|null>>
     */
    private function lines(ExportPlan $plan, Model $row, array $base, array $blankChild): array
    {
        if ($plan->childRelation === null) {
            return [$base];
        }

        $children = $row->getRelation($plan->childRelation)->all();

        if ($children === []) {
            return [[...$base, ...$blankChild]];
        }

        return array_map(function (Model $child) use ($plan, $base): array {
            $child->preventsLazyLoading = Model::preventsLazyLoading();

            return [...$base, ...$this->values($plan->childColumns, $child)];
        }, $children);
    }

    /** Output rows when children are flattened: one per child, or one for a childless document. */
    private function outputRows(Builder $query, string $childRelation): int
    {
        $alias = Str::snake($childRelation).'_count';
        $counted = (clone $query)
            ->reorder()
            ->setEagerLoads([])
            ->select($query->getModel()->getQualifiedKeyName())
            ->withCount($childRelation)
            ->toBase();

        return (int) $query->getModel()->getConnection()->query()
            ->fromSub($counted, 'counted')
            ->selectRaw(sprintf('coalesce(sum(case when %1$s > 0 then %1$s else 1 end), 0) as total', $alias))
            ->value('total');
    }

    /** @param Builder<Model> $builder */
    private function assertChildRelation(ExportPlan $plan, Builder $builder): void
    {
        if ($plan->childRelation === null) {
            return;
        }

        $model = $builder->getModel();

        if (! method_exists($model, $plan->childRelation) || ! $model->{$plan->childRelation}() instanceof HasMany) {
            throw ExportException::invalidRegistration(sprintf(
                'Child relation "%s" on %s must be a HasMany relation.',
                $plan->childRelation,
                $model::class,
            ));
        }
    }

    /** @return array<int|string, string|Closure> */
    private function eagerLoads(ExportPlan $plan): array
    {
        $loads = [];

        foreach ($plan->columns as $column) {
            foreach ($column->getRelations() as $relation) {
                $loads[$relation] ??= null;
            }
        }

        if ($plan->childRelation !== null) {
            $loads[$plan->childRelation] = fn ($query) => $query->orderBy($query->getModel()->getQualifiedKeyName());

            foreach ($plan->childColumns as $column) {
                foreach ($column->getRelations() as $relation) {
                    $loads["{$plan->childRelation}.{$relation}"] ??= null;
                }
            }
        }

        $with = [];
        foreach ($loads as $name => $constraint) {
            $constraint === null ? $with[] = $name : $with[$name] = $constraint;
        }

        return $with;
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
