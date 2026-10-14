<?php

declare(strict_types=1);

namespace Alif\Export\Contracts;

use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Exceptions\ExportException;

/**
 * Where a finished export file lives. The host implements it (e.g. as an attachment) and binds it; the package
 * only writes a local temporary file and hands it over. Listing, links and deletion are the host's.
 */
interface ExportFileStore
{
    /**
     * Keep the file and return the host's identifier for it. The local file is removed by the caller afterwards.
     *
     * @throws ExportException storage_write_failed when it cannot be kept.
     */
    public function put(ExportTask $task, string $localPath, string $fileName, string $mimeType): string;
}
