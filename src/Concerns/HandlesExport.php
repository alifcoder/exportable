<?php

declare(strict_types=1);

namespace Alif\Export\Concerns;

use Alif\Export\Contracts\ExportableFilter;
use Alif\Export\DTO\Export\ExportDTO;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Enums\ExportFormatEnum;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\RequestedColumns;
use Alif\Export\Services\Interfaces\ExportServiceInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** @phpstan-require-implements ExportableFilter */
trait HandlesExport
{
    /**
     * Returns the input the filter was built from (the request data).
     *
     * @return array<string, mixed> The parameters the filter was built from.
     */
    abstract protected function params(): array;

    /**
     * The filter's own fields (`name => Field`, or a bare name), the same names the list filters and sorts by. A
     * `Field` with a `type()` sets the cell type of its export column.
     *
     * @return array<int|string, mixed>
     */
    abstract protected function fields(): array;

    /**
     * The list's ready query: the filter applied, no limit or offset, and a stable ORDER BY (the filter's sort, then the
     * key). The export reads it as given: it never changes it and never adds a limit, offset or ordering. Its eager
     * loads are the relations the job loads; they must be plain (no constraint).
     *
     * @return Builder<Model>
     */
    abstract public function exportQuery(): Builder;

    /**
     * The resource that renders one row, as the list shows it.
     *
     * @return class-string<JsonResource>
     */
    abstract public function exportResource(): string;

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
     * Queues the export and returns its task; the file is announced later by the `ExportFinished` event.
     *
     * @throws ExportException Every failure: `status` 422 when the request can be fixed, 500 otherwise.
     */
    public function export(): ExportTask
    {
        $parameters = $this->params();

        try {
            Validator::make($parameters, $this->exportRules())->validate();
        } catch (ValidationException $e) {
            throw ExportException::invalidRequest($e->errors());
        }

        $columns = (new RequestedColumns($this, $this->exportLists()))->resolve($parameters['columns']);

        return app(ExportServiceInterface::class)->create(new ExportDTO(
            source: $this,
            format: ExportFormatEnum::from($parameters['format']),
            columns: $columns,
        ));
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
}
