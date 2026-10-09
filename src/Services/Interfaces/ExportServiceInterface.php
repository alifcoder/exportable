<?php

declare(strict_types=1);

namespace Alif\Export\Services\Interfaces;

use Alif\Export\Contracts\Exportable;
use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Events\ExportFinished;
use Alif\Export\Exceptions\ExportException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

interface ExportServiceInterface
{
    /**
     * Who may see or export a document is the host's decision; authorise before calling.
     *
     * @throws ExportException Unknown key.
     */
    public function definition(string $key): Exportable;

    /**
     * Queues the export; its result arrives as {@see ExportFinished}. The host authorises $owner before calling.
     *
     * @throws ValidationException When the host filter or the row cap rejects the request.
     * @throws ExportException Unknown key, or too many active exports.
     */
    public function create(Authenticatable $owner, ExportCreateDTO $dto): ExportTask;
}
