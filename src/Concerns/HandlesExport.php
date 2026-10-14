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
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Makes a model filter exportable. It reads from the filter: `params()`, `query()`, `apply()` and the static
 * `$resource`. All validation of an export is {@see ExportValidator}'s and runs before anything is queued. The
 * service then only turns the validated {@see ExportDTO} into a task and queues the job. The
 * received query is only read. The trait's own members are `prepare`, `export`, `assertWithMatchesColumns`, `exportResource`, `exportRules` and `assertParams`.
 */
trait HandlesExport
{
    /**
     * Normalizes the parameters of an export, marked by `export` = true (`export()` sets it): the whole filtered set is
     * exported (the job pages it itself), so `limit` and `offset` are removed, and the relations the request asks
     * for (`with`) are added to the filter's own with `setWith()`. Without the flag the filter's `prepare()` result is
     * returned as it is, so a list keeps its `limit`.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    protected function prepare(array $parameters): array
    {
        $parameters = parent::prepare($parameters);

        if (! filter_var($parameters['export'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return $parameters;
        }

        // Not null: the query filter rejects a null `limit`, an absent one means no page.
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

        // A filter built from the same parameters plus `export` = true: its `prepare()` does the export's part, and the
        // list's own filter is untouched.
        $filter = new static([...$parameters, 'export' => true]);
        $query = $filter->query();
        $filter->assertWithMatchesColumns((array) ($parameters['with'] ?? []), $parameters['columns']);
        // `prepare()` runs first, so the requested relations are in `getWith()` when the query is built.
        $filter->prepare($filter->params());
        $filter->apply($query);

        ExportValidator::assertPageable($query);
        $relations = ExportValidator::validateEagerLoads($query);
        // Counted once: the same number is the progress denominator and the cap check.
        $count = ExportConfig::bool('progress.enabled') ? ExportValidator::countRows($query) : null;
        ExportValidator::assertNotEmpty($query, $count);
        ExportValidator::assertWithinRowLimit($format, $query, $count);

        return app(ExportServiceInterface::class)->create(new ExportDTO(
            query: $query,
            resource: $filter->exportResource(),
            format: $format,
            columns: ExportValidator::validateColumns($query, $filter->exportResource(), $parameters['columns']),
            relations: $relations,
            totalRows: $count,
        ));
    }

    /**
     * A relation the filter does not load by itself is asked for in `with` and read back in `columns`: every `with`
     * name must be a path the requested columns lead through (`business_partner` for `business_partner.name`; a list
     * key such as `products` for `products.*`), written the same or in snake case. A name no column uses is a 422 on
     * `with`.
     *
     * @param  array<mixed>  $requested
     * @param  array<string, mixed>  $columns
     */
    protected function assertWithMatchesColumns(array $requested, array $columns): void
    {
        $paths = $this->columnPaths($columns);

        $unmatched = array_values(array_filter(
            $requested,
            fn (mixed $name): bool => is_string($name)
                && ! isset($paths[$name])
                && ! isset($paths[implode('.', array_map(Str::snake(...), explode('.', $name)))]),
        ));

        if ($unmatched !== []) {
            throw ExportException::invalidRequest(['with' => array_map(
                fn (string $name): string => __('export::messages.unknown_relation', ['relation' => $name]),
                $unmatched,
            )]);
        }
    }

    /**
     * Every dotted path the columns lead through, as keys: `products.product.name` gives `products`,
     * `products.product` and `products.product.name`. A list's own column map continues the list's path.
     *
     * @param  array<string, mixed>  $columns
     * @return array<string, true>
     */
    private function columnPaths(array $columns, string $prefix = ''): array
    {
        $paths = [];

        foreach ($columns as $key => $heading) {
            $path = $prefix.$key;

            foreach (explode('.', $path) as $i => $segment) {
                $paths[implode('.', array_slice(explode('.', $path), 0, $i + 1))] = true;
            }

            if (is_array($heading)) {
                $paths += $this->columnPaths($heading, $path.'.');
            }
        }

        return $paths;
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
