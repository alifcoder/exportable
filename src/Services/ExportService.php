<?php

declare(strict_types=1);

namespace Alif\Export\Services;

use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\DTO\Export\ExportDTO;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Jobs\RunExport;
use Alif\Export\Services\Interfaces\ExportServiceInterface;
use Illuminate\Support\Str;

/** Creates the task of a validated export and queues the job. */
final readonly class ExportService implements ExportServiceInterface
{
    public function create(ExportDTO $request): ExportTask
    {
        $task = new ExportTask((string) Str::uuid(), ExportCreateDTO::from($request), $request->totalRows);
        RunExport::dispatch($task->toArray());

        return $task;
    }
}
