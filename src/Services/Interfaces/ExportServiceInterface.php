<?php

declare(strict_types=1);

namespace Alif\Export\Services\Interfaces;

use Alif\Export\DTO\Export\ExportDTO;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Events\ExportFinished;
use Illuminate\Validation\ValidationException;

interface ExportServiceInterface
{
    /**
     * Checks the columns, counts the rows, compiles the query and queues the export; its result arrives as
     * {@see ExportFinished}. Who may export is the host's decision, made before calling.
     *
     * @throws ValidationException When a column is unknown, the result is empty or over the row cap.
     */
    public function create(ExportDTO $request): ExportTask;
}
