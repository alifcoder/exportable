<?php

declare(strict_types=1);

namespace Alif\Export\Concerns;

use Alif\Export\DTO\Export\ExportColumn;
use Alif\Export\DTO\Export\ExportRequest;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Enums\ExportFormat;
use Alif\Export\Services\Interfaces\ExportServiceInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

trait HandlesExport
{
    /** @return array<string, mixed> The parameters the filter was built from. */
    abstract protected function params(): array;

    abstract public function apply(Builder $builder): void;

    /** Queues the export and returns its task; the file is announced later by the `ExportFinished` event. */
    public function export(): ExportTask
    {
        $parameters = $this->params();
        Validator::make($parameters, $this->exportRules())->validate();

        $query = $this->exportQuery();
        $this->apply($query);
        $this->exportWindow($query);

        return app(ExportServiceInterface::class)->create(new ExportRequest(
            source: static::class,
            format: ExportFormat::from($parameters['format']),
            query: $query,
            columns: $this->exportColumns($parameters['columns']),
        ));
    }

    /**
     * What the queued job needs to read the rows: the model, the full resource and the relations the resource
     * touches (`$with`, keys and constraints kept).
     *
     * @return array{model: class-string<Model>, resource: class-string<JsonResource>, with: array<int|string, string|Closure>}
     */
    public static function exportSource(): array
    {
        return [
            'model' => static::$model,
            'resource' => static::$resource,
            // The instance default, so a filter that overrides getWith() (e.g. a mini list) is honoured.
            'with' => (new static([]))->getWith(),
        ];
    }

    /**
     * The list's base query before the filter's conditions: override to start from the filter's own selects,
     * counts and ordering instead of the bare model.
     *
     * @return Builder<Model>
     */
    protected function exportQuery(): Builder
    {
        return static::$model::query();
    }

    /** Override to limit the query to the page the list would show; by default the whole filtered set is exported. */
    protected function exportWindow(Builder $query): void {}

    /**
     * Whether a key exists is decided by the export itself, from the resource.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function exportRules(): array
    {
        return [
            'format' => ['required', Rule::enum(ExportFormat::class)],
            'columns' => ['required', 'array', 'min:1', 'max:200'],
        ];
    }

    /**
     * @param  array<string, string>  $columns  Validated by {@see exportRules()}.
     * @return list<ExportColumn>
     */
    private function exportColumns(array $columns): array
    {
        $list = [];

        foreach ($columns as $key => $label) {
            $list[] = new ExportColumn((string) $key, (string) $label);
        }

        return $list;
    }
}
