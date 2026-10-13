<?php

declare(strict_types=1);

namespace Alif\Export\DTO\Export;

use Alif\Export\Exceptions\ExportException;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use ReflectionClass;
use ReflectionFunction;

/**
 * How the job reads the rows of the prepared SQL: the model to hydrate, the resource that renders it and the relations
 * to eager load (the names the host's query eager loads). Scalar-only so it travels in a job payload.
 */
final readonly class RowSource
{
    /**
     * @param  class-string<Model>  $model
     * @param  class-string<JsonResource>  $resource
     * @param  list<string>  $relations
     */
    public function __construct(
        public string $model,
        public string $resource,
        public array $relations,
    ) {}

    /**
     * Records the model and the eager loads of the host's query. A constrained eager load cannot be repeated by the job.
     *
     * @param  Builder<*>  $builder
     * @param  class-string<JsonResource>  $resource
     *
     * @throws ExportException An eager load with a constraint of its own.
     */
    public static function from(Builder $builder, string $resource): self
    {
        $relations = [];

        foreach ($builder->getEagerLoads() as $name => $constraint) {
            if (! self::isPlain($constraint)) {
                throw ExportException::unsupportedEagerLoad((string) $name);
            }

            $relations[] = (string) $name;
        }

        return new self(get_class($builder->getModel()), $resource, $relations);
    }

    /**
     * Builds the source back from the array saved in the job payload.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['model'], $data['resource'], array_values($data['relations'] ?? []));
    }

    /**
     * Turns the source into a plain array for the job payload.
     *
     * @return array{model: class-string<Model>, resource: class-string<JsonResource>, relations: list<string>}
     */
    public function toArray(): array
    {
        return ['model' => $this->model, 'resource' => $this->resource, 'relations' => $this->relations];
    }

    /**
     * A plain eager load is one Eloquent itself declared without constraining anything: Eloquent wraps every
     * constraint of a relation in a closure of its own that holds the originals, so each one is looked at. A closure
     * declared anywhere else, or one Eloquent built from a `relation:column` select, is a constraint.
     */
    private static function isPlain(Closure $constraint): bool
    {
        $function = new ReflectionFunction($constraint);

        if ($function->getFileName() !== (new ReflectionClass(Builder::class))->getFileName()) {
            return false;
        }

        $captured = $function->getStaticVariables();

        if ($captured === []) {
            return true;
        }

        if (array_keys($captured) !== ['constraints'] || ! is_array($captured['constraints'])) {
            return false;
        }

        foreach ($captured['constraints'] as $inner) {
            if (! $inner instanceof Closure || ! self::isPlain($inner)) {
                return false;
            }
        }

        return true;
    }
}
