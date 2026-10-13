<?php

declare(strict_types=1);

namespace Alif\Export\Services;

use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\DTO\Export\ExportDTO;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\DTO\Export\PreparedQuery;
use Alif\Export\Helpers\ExportBuilder;
use Alif\Export\Helpers\ExportConfig;
use Alif\Export\Jobs\RunExport;
use Alif\Export\Services\Interfaces\ExportServiceInterface;
use Illuminate\Support\Str;

/** Creates an export: checks the request and queues the job. */
final readonly class ExportService implements ExportServiceInterface
{
    public function __construct(private ExportBuilder $builder) {}

    /**
     * Everything is decided here, before the job exists: the columns are checked, the rows counted, the query
     * compiled. The job only reads the prepared SQL and writes the file.
     */
    public function create(ExportDTO $request): ExportTask
    {
        $query = $this->builder->ordered($request->query);
        // The export is the whole filtered set: a page limit the list put on the query is dropped.
        $query->getQuery()->limit = null;
        $query->getQuery()->offset = null;
        // Counted once: the same number is the progress denominator and the cap check.
        $rows = ExportConfig::bool('progress.enabled') ? $this->builder->countRows($query) : null;
        $this->builder->assertNotEmpty($query, $rows);
        $this->builder->assertWithinCap($request->format, $query, $rows);

        $dto = new ExportCreateDTO(
            $request->source,
            $request->format,
            $this->builder->describe($request, $query),
            PreparedQuery::from($query),
        );

        $task = new ExportTask((string) Str::uuid(), $dto, $rows);
        RunExport::dispatch($task->toArray());

        return $task;
    }
}
