<?php

declare(strict_types=1);

namespace Alif\Export\Services\Interfaces;

use Alif\Export\DTO\Export\ExportDTO;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Events\ExportFinished;
use Alif\Export\Exceptions\ExportException;

interface ExportServiceInterface
{
    /**
     * Checks the query and the columns, counts the rows, compiles the query and queues the export; its result arrives as
     * {@see ExportFinished}. Who may export is the host's decision, made before calling. The query is only read.
     *
     * @throws ExportException `unpageable_query` or `unsupported_eager_load` (500) for a query the job cannot read;
     *                         `unknown_column`, `no_data` or `too_many_rows` (422); `resource_failed` (500).
     */
    public function create(ExportDTO $request): ExportTask;
}
