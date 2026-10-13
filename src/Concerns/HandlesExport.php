<?php

declare(strict_types=1);

namespace Alif\Export\Concerns;

use Alif\Export\DTO\Export\ExportColumn;
use Alif\Export\DTO\Export\ExportDTO;
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

    /**
     * Returns the input the filter was built from (the request data).
     *
     * @return array<string, mixed> The parameters the filter was built from.
     */
    abstract protected function params(): array;

    /**
     * The list's base query before the filter's conditions (its own selects, counts and ordering); the export starts from it.
     *
     * @return Builder<Model>
     */
    abstract protected function query(): Builder;

    /**
     * The filter's own fields (`name => Field`, or a bare name), the same names the list filters and sorts by.
     *
     * @return array<int|string, mixed>
     */
    abstract protected function fields(): array;

    /** Adds the filter's conditions to the query. */
    abstract public function apply(Builder $builder): void;

    /** Queues the export and returns its task; the file is announced later by the `ExportFinished` event. */
    public function export(): ExportTask
    {
        $parameters = $this->params();
        Validator::make($parameters, $this->exportRules())->validate();

        $query = $this->query();
        $this->apply($query);

        return app(ExportServiceInterface::class)->create(new ExportDTO(
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

        $this->assertExportable($list);

        return $list;
    }

    /**
     * Rejects every document column that is not one of the filter's `fields()` names, naming each one. The items of a
     * list are not filter fields; they are checked against the resource.
     *
     * @param  list<ExportColumn>  $columns
     *
     * @throws ValidationException
     */
    private function assertExportable(array $columns): void
    {
        $names = [];

        foreach ($this->fields() as $name => $field) {
            $names[] = (string) (is_string($name) ? $name : $field);
        }

        $refused = array_filter($columns, fn (ExportColumn $c): bool => $c->groups === [] && ! in_array($c->key, $names, true));

        if ($refused !== []) {
            throw ValidationException::withMessages(['columns' => array_values(array_map(
                fn (ExportColumn $c): string => sprintf('Column "%s" is not a field of the filter.', $c->key),
                $refused,
            ))]);
        }
    }

    /**
     * Turns the nested columns array into one flat list. Each column remembers which list it belongs to.
     *
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
