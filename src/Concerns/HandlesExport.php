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
use Illuminate\Validation\ValidationException;

trait HandlesExport
{
    private const int MAX_COLUMNS = 200;

    /** Lists inside lists, counted from the document. */
    private const int MAX_DEPTH = 5;

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
            'columns' => ['required', 'array', 'min:1', 'max:'.self::MAX_COLUMNS],
        ];
    }

    /**
     * `key => heading`, and `list => {key => heading, ...}` for the columns read from each item of a list the resource
     * renders (`products`); a list may hold further lists to any depth. Lists are written as sub rows below their parent.
     *
     * @param  array<string, string|array<string, mixed>>  $columns  Validated by {@see exportRules()}.
     * @return list<ExportColumn>
     *
     * @throws ValidationException
     */
    private function exportColumns(array $columns): array
    {
        $list = $this->flattenColumns($columns, []);

        if (count($list) > self::MAX_COLUMNS) {
            throw ValidationException::withMessages(['columns' => [sprintf('At most %d columns can be exported.', self::MAX_COLUMNS)]]);
        }

        if (array_filter($list, fn (ExportColumn $c): bool => $c->groups === []) === []) {
            throw ValidationException::withMessages(['columns' => ['At least one column must come from the document itself, not from a list.']]);
        }

        return $list;
    }

    /**
     * @param  array<string, mixed>  $columns
     * @param  list<string>  $groups
     * @return list<ExportColumn>
     *
     * @throws ValidationException
     */
    private function flattenColumns(array $columns, array $groups): array
    {
        $list = [];

        foreach ($columns as $key => $label) {
            if (! is_array($label)) {
                $list[] = new ExportColumn((string) $key, (string) $label, null, $groups);

                continue;
            }

            if (count($groups) >= self::MAX_DEPTH) {
                throw ValidationException::withMessages(['columns' => [sprintf('Lists can be nested %d levels deep at most.', self::MAX_DEPTH)]]);
            }

            array_push($list, ...$this->flattenColumns($label, [...$groups, (string) $key]));
        }

        return $list;
    }
}
