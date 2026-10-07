<?php

declare(strict_types=1);

namespace Alif\Export\Services\Interfaces;

use Alif\Export\Contracts\Exportable;
use Alif\Export\DTO\Export\ExportCreateDTO;
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
    public function findOwned(Authenticatable $owner, string $id): DataExport;

    /**
     * @throws ValidationException When the host filter or the row cap rejects the request.
     * @throws ExportException Too many active exports.
     */
    public function create(Authenticatable $owner, ExportCreateDTO $dto): DataExport;

    /**
     * @throws ExportException Not failed, forbidden, or too many active exports.
     * @throws ValidationException When the definition no longer has the chosen columns.
     */
    public function retry(Authenticatable $owner, DataExport $failed): DataExport;

    /**
     * Cancels a pending export or deletes a finished one together with its file.
     *
     * @throws ExportException Being processed.
     */
    public function delete(DataExport $export): void;

    /** @throws ExportException Forbidden, not ready, or the file is gone. */
    public function assertDownloadable(Authenticatable $owner, DataExport $export): void;
}
