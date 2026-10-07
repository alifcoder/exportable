<?php

declare(strict_types=1);

namespace Alif\Export\Services\Interfaces;

use Alif\Export\Contracts\Exportable;
use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\DTO\Export\ExportDownloadDTO;
use Alif\Export\DTO\Export\ExportListDTO;
use Alif\Export\Entities\DataExport;
use Alif\Export\Exceptions\ExportException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Validation\ValidationException;

interface ExportServiceInterface
{
    /**
     * Unknown and forbidden keys answer alike, so the caller cannot learn which keys exist.
     *
     * @throws ExportException Forbidden.
     */
    public function definition(Authenticatable $user, string $key): Exportable;

    /** @return Paginator<int, DataExport> */
    public function all(ExportListDTO $dto): Paginator;

    /** @throws ExportException Not found, or not owned by $owner. */
    public function find(Authenticatable $owner, string $id): DataExport;

    /**
     * @throws ValidationException When the host filter or the row cap rejects the request.
     * @throws ExportException Too many active exports.
     */
    public function create(Authenticatable $owner, ExportCreateDTO $dto): DataExport;

    /**
     * Starts a new export with the options of the owner's failed one.
     *
     * @throws ExportException Not found, not failed, forbidden, or too many active exports.
     * @throws ValidationException When the definition no longer has the chosen columns.
     */
    public function retry(Authenticatable $owner, string $id): DataExport;

    /**
     * Cancels a pending export or deletes a finished one together with its file.
     *
     * @throws ExportException Not found, or being processed.
     */
    public function delete(Authenticatable $owner, string $id): void;

    /**
     * Where the file of a completed export lives and how to serve it. The permission is re-checked on every call.
     *
     * @throws ExportException Not found, forbidden, not ready, or the file is gone.
     */
    public function download(Authenticatable $owner, string $id): ExportDownloadDTO;
}
