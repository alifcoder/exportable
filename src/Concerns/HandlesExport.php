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
use Illuminate\Validation\Rule;

/**
 * Makes a model filter exportable. It reads from the filter: `params()`, `query()`, `apply()` and the static
 * `$resource`. All validation of an export is {@see ExportValidator}'s and runs before anything is queued. The
 * service then only turns the validated {@see ExportDTO} into a task and queues the job. The
 * received query is only read. The trait's own members are `prepare`, `export`, `exportRules` and `assertParams`.
 */
trait HandlesExport
{
    /** Normalize request input before either request-level or field-level validation. */
    protected function prepare(array $parameters): array
    {
        // The whole filtered set is exported; the job pages it itself.
        unset($parameters['limit'], $parameters['offset']);

        return $parameters; // Preserve false, zero and null unless a subclass changes them.
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

        $query = $this->query();
        $this->apply($query);

        ExportValidator::assertPageable($query);
        $relations = ExportValidator::validateEagerLoads($query);
        // Counted once: the same number is the progress denominator and the cap check.
        $count = ExportConfig::bool('progress.enabled') ? ExportValidator::countRows($query) : null;
        ExportValidator::assertNotEmpty($query, $count);
        ExportValidator::assertWithinRowLimit($format, $query, $count);

        return app(ExportServiceInterface::class)->create(new ExportDTO(
            query: $query,
            resource: static::$resource,
            format: $format,
            columns: ExportValidator::validateColumns($query, static::$resource, $parameters['columns']),
            relations: $relations,
            totalRows: $count,
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
