<?php

declare(strict_types=1);

namespace Alif\Export\Concerns;

use Alif\Export\DTO\Export\ExportDTO;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Enums\ExportFormatEnum;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Services\Interfaces\ExportServiceInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Makes a model filter exportable. It reads from the filter: `params()`, `query()`, `apply()` and the
 * static `$resource`. The requested columns are checked against what the resource yields, nested lists included.
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
     * Queues the export and returns its task; the file is announced later by the `ExportFinished` event.
     *
     * @throws ExportException Every failure: `status` 422 when the request can be fixed, 500 otherwise.
     */
    public function export(): ExportTask
    {
        $parameters = $this->assertParams();

        $query = $this->query();
        $this->apply($query);

        return app(ExportServiceInterface::class)->create(new ExportDTO(
            query: $query,
            resource: static::$resource,
            format: ExportFormatEnum::from($parameters['format']),
            columns: $parameters['columns'],
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

        try {
            Validator::make($parameters, $this->exportRules())->validate();
        } catch (ValidationException $e) {
            throw ExportException::invalidRequest($e->errors());
        }

        return $parameters;
    }
}
