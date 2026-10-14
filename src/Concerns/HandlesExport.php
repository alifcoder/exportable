<?php

declare(strict_types=1);

namespace Alif\Export\Concerns;

use Alif\Export\DTO\Export\ExportDTO;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Enums\ExportFormatEnum;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportConfig;
use Alif\Export\Helpers\ExportValidator;
use Alif\Export\Services\Interfaces\ExportServiceInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Makes a model filter exportable. It reads from the filter: `params()`, `query()`, `apply()` and the static
 * `$resource`. All validation of an export is {@see ExportValidator}'s and runs before anything is queued. The
 * service then only turns the validated {@see ExportDTO} into a task and queues the job. The
 * received query is only read. The trait's own members are `prepare`, `export`, `assertKnownRelations`, `exportResource`, `exportRules` and `assertParams`.
 */
trait HandlesExport
{
    /** True only while `export()` runs, so the list's own use of the filter is untouched. */
    private bool $exporting = false;

    /**
     * Normalizes the parameters of an export: the whole filtered set is exported (the job pages it itself), so `limit`
     * and `offset` are dropped, and the relations the request asks for (`with`) are added to the filter's own with
     * `setWith()`. A list request is passed through as the filter's `prepare()` returns it.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    protected function prepare(array $parameters): array
    {
        $parameters = parent::prepare($parameters);

        if (! $this->exporting) {
            return $parameters;
        }

        unset($parameters['limit'], $parameters['offset']);

        $relations = $this->getWith();
        $requested = array_values(array_filter((array) ($parameters['with'] ?? []), is_string(...)));
        $this->setWith(array_merge($relations, array_values(array_diff($requested, $relations))));

        return $parameters;
    }

    /**
     * Validates the request, the query and the columns, then hands the service the validated export to queue; the file
     * is announced later by the `ExportFinished` event. Who may export is the host's decision, made before calling.
     *
     * @throws ExportException Every failure: `status` 422 when the request can be fixed, 500 otherwise.
     */
    public function export(): ExportTask
    {
        $parameters = $this->assertParams();
        $format = ExportFormatEnum::from($parameters['format']);

        $this->exporting = true;

        try {
            $query = $this->query();
            $this->assertKnownRelations($query, (array) ($parameters['with'] ?? []));
            // `prepare()` runs first, so the requested relations are in `getWith()` when the query is built.
            $this->prepare($parameters);
            $this->apply($query);
        } finally {
            $this->exporting = false;
        }

        ExportValidator::assertPageable($query);
        $relations = ExportValidator::validateEagerLoads($query);
        // Counted once: the same number is the progress denominator and the cap check.
        $count = ExportConfig::bool('progress.enabled') ? ExportValidator::countRows($query) : null;
        ExportValidator::assertNotEmpty($query, $count);
        ExportValidator::assertWithinRowLimit($format, $query, $count);

        return app(ExportServiceInterface::class)->create(new ExportDTO(
            query: $query,
            resource: $this->exportResource(),
            format: $format,
            columns: ExportValidator::validateColumns($query, $this->exportResource(), $parameters['columns']),
            relations: $relations,
            totalRows: $count,
        ));
    }

    /**
     * A requested `with` name the model has no relation method for is a 422 on `with`, so it never fails as a 500
     * while the query is built.
     *
     * @param  Builder<Model>  $query
     * @param  array<mixed>  $requested
     */
    protected function assertKnownRelations(Builder $query, array $requested): void
    {
        $model = $query->getModel();

        $unknown = array_values(array_filter(
            $requested,
            fn (mixed $name): bool => is_string($name) && ! method_exists($model, Str::before($name, '.')),
        ));

        if ($unknown !== []) {
            throw ExportException::invalidRequest(['with' => array_map(
                fn (string $name): string => __('export::messages.unknown_relation', ['relation' => $name]),
                $unknown,
            )]);
        }
    }

    /** The resource the list renders its rows with: the short one for `short=1` when the filter has one. */
    protected function exportResource(): string
    {
        if ($this->isShort() && property_exists(static::class, 'shortResource') && static::$shortResource !== null) {
            return static::$shortResource;
        }

        return static::$resource;
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
            'with' => ['sometimes', 'array'],
            'with.*' => ['string'],
        ];
    }

    /**
     * @return mixed[]
     */
    public function assertParams(): array
    {
        $parameters = $this->params();

        ExportValidator::assertValidRequest($parameters, $this->exportRules());

        return $parameters;
    }
}
