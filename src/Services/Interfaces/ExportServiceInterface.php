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
     * Unknown and forbidden keys answer alike, so the caller cannot learn which keys exist.
     *
     * @throws ExportException Forbidden.
     */
    public function definition(Authenticatable $user, string $key): Exportable;

    /**
     * Queues the export; its result arrives as {@see ExportFinished}.
     *
     * @throws ValidationException When the host filter or the row cap rejects the request.
     * @throws ExportException Too many active exports.
     */
    public function create(Authenticatable $owner, ExportCreateDTO $dto): ExportTask;
}
