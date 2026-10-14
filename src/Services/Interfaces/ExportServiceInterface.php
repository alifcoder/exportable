<?php

declare(strict_types=1);

namespace Alif\Export\Services\Interfaces;

use Alif\Export\DTO\Export\ExportDTO;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Events\ExportFinished;

interface ExportServiceInterface
{
    /**
     * Compiles the validated export's query, creates its task and queues the job; its result arrives as
     * {@see ExportFinished}. Validation is `HandlesExport`'s: the query is only read.
     */
    public function create(ExportDTO $request): ExportTask;
}
