<?php

declare(strict_types=1);

namespace Alif\Export\DTO\Export;

use Alif\Export\Enums\ExportFormatEnum;
use Alif\Export\Exceptions\ExportException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the job writes, fully resolved on the request: format, typed columns, the prepared query and how to read its
 * rows (the model to hydrate, the resource that renders it, the relations to eager load). Scalar-only so it travels in a job payload.
 */
final readonly class ExportCreateDTO
{
    /**
     * @param  list<ExportColumn>  $columns
     * @param  class-string<Model>  $model
     * @param  class-string<JsonResource>  $resource
     * @param  list<string>  $relations  The names the host's query eager loads.
     */
    public function __construct(
        public ExportFormatEnum $format,
        public array $columns,
        public PreparedQuery $query,
        public string $model,
        public string $resource,
        public array $relations,
    ) {}

    /**
     * Prepares the validated export for the queue: the query compiled to SQL, the model, resource and eager loads recorded.
     *
     * @throws ExportException A driver that cannot be paged.
     */
    public static function from(ExportDTO $request): self
    {
        return new self(
            $request->format,
            $request->columns,
            PreparedQuery::from($request->query),
            get_class($request->query->getModel()),
            $request->resource,
            $request->relations,
        );
    }

    /**
     * Builds the DTO back from the array saved in the job payload.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ExportException A payload queued by an older version, without `model`.
     */
    public static function fromArray(array $data): self
    {
        if (! isset($data['model'], $data['resource'])) {
            throw ExportException::invalidRegistration('The queued export has no "model"; it was queued by an older version.');
        }

        return new self(
            format: ExportFormatEnum::from($data['format']),
            columns: array_map(ExportColumn::fromArray(...), $data['columns']),
            query: PreparedQuery::fromArray($data['query']),
            model: $data['model'],
            resource: $data['resource'],
            relations: array_values($data['relations'] ?? []),
        );
    }

    /**
     * Turns the DTO into a plain array for the job payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format' => $this->format->value,
            'columns' => array_map(fn (ExportColumn $c): array => $c->toArray(), $this->columns),
            'query' => $this->query->toArray(),
            'model' => $this->model,
            'resource' => $this->resource,
            'relations' => $this->relations,
        ];
    }
}
