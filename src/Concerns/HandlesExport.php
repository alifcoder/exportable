<?php

declare(strict_types=1);

namespace Alif\Export\Concerns;

use Alif\Export\DTO\Export\ExportColumn;
use Alif\Export\DTO\Export\ExportDTO;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Enums\ExportFormatEnum;
use Alif\Export\Exceptions\ExportException;
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
            format: ExportFormatEnum::from($parameters['format']),
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
            'format' => ['required', Rule::enum(ExportFormatEnum::class)],
            'columns' => ['required', 'array', 'min:1'],
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

        if (array_filter($list, fn (ExportColumn $c): bool => $c->groups === []) === []) {
            throw ValidationException::withMessages(['columns' => [__('export::messages.needs_document_column')]]);
        }

        $this->assertExportable($columns);

        return $list;
    }

    /**
     * The lists a filter lets out, each read by the filter of its items: `list => ItemFilter::class`, or
     * `list => [ItemFilter::class, 'inner' => InnerFilter::class]` when the items hold lists of their own. The keys of a
     * list's items must be `fields()` names of that item filter. By default no list can be exported.
     *
     * @return array<string, class-string|array<int|string, mixed>>
     */
    protected function exportLists(): array
    {
        return [];
    }

    /**
     * Rejects every column the filter does not offer, naming each one: a document column must be one of the filter's
     * `fields()` names, a list column one of its item filter's (see {@see exportLists()}).
     *
     * @param  array<string, mixed>  $columns  The requested columns as sent, lists nested.
     *
     * @throws ValidationException
     */
    private function assertExportable(array $columns): void
    {
        $refused = $this->refusedColumns($columns, $this->fieldNames($this), $this->exportLists(), '');

        if ($refused !== []) {
            throw ValidationException::withMessages(['columns' => array_map(
                fn (string $path): string => __('export::messages.column_not_offered', ['column' => $path]),
                $refused,
            )]);
        }
    }

    /**
     * The columns of one level that are not offered, then those of every list below it, each read against the filter
     * of that list's items. A list the filter does not name is refused as a whole.
     *
     * @param  array<string, mixed>  $columns
     * @param  list<string>  $fieldNames  The names the filter of this level offers.
     * @param  array<int|string, mixed>  $lists  The lists this level may hold (see {@see exportLists()}).
     * @return list<string> Dotted paths.
     */
    private function refusedColumns(array $columns, array $fieldNames, array $lists, string $prefix): array
    {
        $refused = [];

        foreach ($columns as $key => $value) {
            $key = (string) $key;

            if (! is_array($value)) {
                if (! in_array($key, $fieldNames, true)) {
                    $refused[] = $prefix.$key;
                }

                continue;
            }

            $entry = $lists[$key] ?? null;
            $itemFilter = is_array($entry) ? ($entry[0] ?? null) : $entry;

            if (! is_string($itemFilter)) {
                $refused[] = $prefix.$key;

                continue;
            }

            if (! class_exists($itemFilter)) {
                throw ExportException::invalidRegistration(sprintf('exportLists() names "%s" for the list "%s", which is not a class.', $itemFilter, $key));
            }

            // The item filter's own `[0]` is not a list name: only its string keys are the lists of its items.
            $inner = is_array($entry) ? array_diff_key($entry, [0 => null]) : [];

            array_push($refused, ...$this->refusedColumns($value, $this->fieldNames(new $itemFilter([])), $inner, $prefix.$key.'.'));
        }

        return $refused;
    }

    /**
     * The names a filter's `fields()` offers (`name => Field`, or a bare name).
     *
     * @return list<string>
     */
    private function fieldNames(object $filter): array
    {
        $names = [];

        // Reflection, so an item filter need not share a base class with this one for a protected fields() to be read.
        foreach ((new \ReflectionMethod($filter, 'fields'))->invoke($filter) as $name => $field) {
            $names[] = (string) (is_string($name) ? $name : $field);
        }

        return $names;
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
                throw ValidationException::withMessages(['columns' => [__('export::messages.nested_too_deep', ['max' => self::MAX_DEPTH])]]);
            }

            array_push($list, ...$this->flattenColumns($label, [...$groups, (string) $key]));
        }

        return $list;
    }
}
