<?php

declare(strict_types=1);

namespace Alif\Export\Services;

use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\DTO\Export\ExportDTO;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Helpers\ColumnResolver;
use Alif\Export\Helpers\ExportConfig;
use Alif\Export\Helpers\ExportQuery;
use Alif\Export\Jobs\RunExport;
use Alif\Export\Services\Interfaces\ExportServiceInterface;
use Illuminate\Support\Str;

/** Creates an export: checks the request and queues the job. */
final readonly class ExportService implements ExportServiceInterface
{
    public function __construct(
        private ExportQuery $query,
        private ColumnResolver $columns,
    ) {}

    /**
     * Everything is decided here, before the job exists: the query is checked, the rows counted, the columns checked,
     * the query compiled. The received query is only read, never changed. The job only reads the prepared SQL and
     * writes the file.
     */
    public function create(ExportDTO $request): ExportTask
    {
        // One builder: every check and the compiled SQL read it.
        $query = $request->query;
        $this->query->assertPageable($query);
        $relations = $this->query->eagerLoads($query);
        // Counted once: the same number is the progress denominator and the cap check.
        $count = ExportConfig::bool('progress.enabled') ? $this->query->count($query) : null;
        $this->query->assertNotEmpty($query, $count);
        $this->query->assertWithinCap($request->format, $query, $count);

        $dto = ExportCreateDTO::from($request, $this->columns->resolve($request), $relations);
        $task = new ExportTask((string) Str::uuid(), $dto, $count);
        RunExport::dispatch($task->toArray());

        return $task;
    }
}
