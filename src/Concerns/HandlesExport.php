<?php

declare(strict_types=1);

namespace Alif\Export\Concerns;

use Alif\Export\Contracts\ExportSource;
use Alif\Export\DTO\Export\ExportColumn;
use Alif\Export\DTO\Export\ExportRequest;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Enums\ExportFormat;
use Alif\Export\Services\Interfaces\ExportServiceInterface;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Exports the list a query filter serves. The request is the list's own (`filter`, `sort`, `search`, `paginate`,
 * ...) plus two parts of the export:
 *
 * ```
 * { "format": "excel",
 *   "columns": { "prefix-index": "Document Number", "outlet.name": "Outlet Name",
 *                "products": { "sequence": "Sequence", "product.name": "Product" } } }
 * ```
 *
 * `columns` maps a key of the filter's resource (dotted for nested objects) to the heading the client wants; an
 * object value names a HasMany relation whose rows are written one per line next to the document's columns.
 *
 * Everything is settled on the request: the export rules and the filter's own parameters are validated, the
 * filter (with the caller's access limits) is applied, the columns are checked against what the resource yields,
 * the rows are counted and the query is compiled into the queued task. The job reads that query; it does not
 * rebuild the filter and needs no signed-in user. Who may export is the host's policy, checked before calling
 * {@see export()}.
 *
 * The using class is a query filter that provides `static::$model`, `static::$resource`, `apply()`, `prepare()`,
 * `getWith()` and `givenParameters()`, and implements {@see ExportSource}.
 *
 * @phpstan-require-implements ExportSource
 */
trait HandlesExport
{
    /** @return array<string, mixed> The parameters the filter was built from. */
    abstract protected function givenParameters(): array;

    /** @param array<string, mixed> $parameters */
    abstract protected function prepare(array $parameters): array;

    abstract public function apply(Builder $builder): void;

    /**
     * Queues the export and returns its task; the file is announced later by the `ExportFinished` event.
     *
     * @param  Authenticatable|null  $owner  Defaults to the signed-in user. Counts against the quota and receives
     *                                       the events; it is not signed in again by the job.
     */
    public function export(?Authenticatable $owner = null): ExportTask
    {
        $owner ??= auth()->user() ?? throw new AuthenticationException;

        $parameters = $this->prepare($this->givenParameters());
        Validator::make($parameters, $this->exportRules())->validate();

        [$columns, $relation, $childColumns] = $this->exportColumns($parameters['columns']);

        $query = $this->exportQuery();
        $this->apply($query);
        $this->exportWindow($query);

        return app(ExportServiceInterface::class)->create($owner, new ExportRequest(
            source: static::class,
            format: ExportFormat::from($parameters['format']),
            query: $query,
            columns: $columns,
            childRelation: $relation,
            childColumns: $childColumns,
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
            'columns' => ['required', 'array', 'min:1', 'max:200', function (string $attribute, mixed $value, Closure $fail): void {
                if (($problem = $this->columnProblem((array) $value, true)) !== null) {
                    $fail($problem);
                }
            }],
        ];
    }

    /**
     * Keys are free text (dotted, hyphenated), so the map is checked here instead of with wildcard rules, which
     * read a dot in a key as nesting.
     *
     * @param  array<int|string, mixed>  $columns
     */
    private function columnProblem(array $columns, bool $root): ?string
    {
        $nested = 0;

        foreach ($columns as $key => $label) {
            if (! is_string($key) || $key === '' || mb_strlen($key) > 150) {
                return 'The columns must map a column key to its heading.';
            }

            if (is_array($label)) {
                if (! $root || ++$nested > 1 || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                    return sprintf('"%s" cannot hold columns: only one relation may be exported with its rows.', $key);
                }

                if ($label === [] || ($problem = $this->columnProblem($label, false)) !== null) {
                    return $problem ?? sprintf('"%s" needs at least one column.', $key);
                }

                continue;
            }

            if (! is_string($label) || trim($label) === '' || mb_strlen($label) > 150) {
                return sprintf('The heading of "%s" must be 1 to 150 characters.', $key);
            }
        }

        return null;
    }

    /**
     * @param  array<string, string|array<string, string>>  $columns  Validated by {@see exportRules()}.
     * @return array{0: list<ExportColumn>, 1: string|null, 2: list<ExportColumn>} Document columns, child relation, child columns.
     */
    private function exportColumns(array $columns): array
    {
        $document = [];
        $relation = null;
        $children = [];

        foreach ($columns as $key => $label) {
            if (is_array($label)) {
                $relation = $key;
                $children = $this->exportColumnList($label);

                continue;
            }

            $document[] = new ExportColumn($key, $label);
        }

        return [$document, $relation, $children];
    }

    /**
     * @param  array<string, string>  $columns
     * @return list<ExportColumn>
     */
    private function exportColumnList(array $columns): array
    {
        $list = [];

        foreach ($columns as $key => $label) {
            $list[] = new ExportColumn($key, $label);
        }

        return $list;
    }
}
