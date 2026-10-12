<?php

declare(strict_types=1);

namespace Alif\Export\Services\Interfaces;

use Alif\Export\DTO\Export\ExportRequest;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Events\ExportFinished;
use Alif\Export\Exceptions\ExportException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

interface ExportServiceInterface
{
    /**
     * Checks the columns, counts the rows, compiles the query and queues the export; its result arrives as
     * {@see ExportFinished}. The owner only counts against the quota and receives the events: who may export is
     * the host's decision, made before calling.
     *
     * @throws ValidationException When a column is unknown, the result is empty or over the row cap.
     * @throws ExportException Too many active exports.
     */
    public function create(Authenticatable $owner, ExportRequest $request): ExportTask;
}
