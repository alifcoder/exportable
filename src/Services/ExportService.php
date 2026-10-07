<?php

declare(strict_types=1);

namespace Alif\Export\Services;

use Alif\Export\Contracts\Exportable;
use Alif\Export\Contracts\ExportAuth;
use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\DTO\Export\ExportListDTO;
use Alif\Export\Entities\DataExport;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportRegistry;
use Alif\Export\Services\Actions\Export\RetryExport;
use Alif\Export\Services\Actions\Export\StartExport;
use Alif\Export\Services\Interfaces\ExportServiceInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Facades\Storage;

final readonly class ExportService implements ExportServiceInterface
{
    public function __construct(
        private ExportRegistry $registry,
        private ExportAuth $auth,
        private StartExport $startExport,
        private RetryExport $retryExport,
    ) {}

    public function definition(Authenticatable $user, string $key): Exportable
    {
        if (! $this->registry->has($key) || ! $this->auth->allows($user, $key)) {
            throw ExportException::forbidden();
        }

        return $this->registry->get($key);
    }

    public function all(ExportListDTO $dto): Paginator
    {
        return DataExport::query()
            ->where('owner_id', $dto->ownerId)
            ->when($dto->status !== null, fn ($q) => $q->where('status', $dto->status))
            ->when($dto->exportable !== null, fn ($q) => $q->where('exportable', $dto->exportable))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->simplePaginate($dto->perPage);
    }

    public function findOwned(Authenticatable $owner, string $id): DataExport
    {
        $export = DataExport::query()->find($id);

        if ($export === null || (string) $export->owner_id !== (string) $owner->getAuthIdentifier()) {
            throw ExportException::notFound();
        }

        return $export;
    }

    public function create(Authenticatable $owner, ExportCreateDTO $dto): DataExport
    {
        return ($this->startExport)($owner, $dto);
    }

    public function retry(Authenticatable $owner, DataExport $failed): DataExport
    {
        return ($this->retryExport)($owner, $failed);
    }

    public function delete(DataExport $export): void
    {
        if (! $export->deleteIfIdle()) {
            throw ExportException::beingProcessed();
        }

        if ($export->disk !== null && $export->path !== null) {
            Storage::disk($export->disk)->delete($export->path);
        }
    }

    public function assertDownloadable(Authenticatable $owner, DataExport $export): void
    {
        if (! $this->auth->allows($owner, $export->exportable)) {
            throw ExportException::forbidden();
        }

        if ($export->status !== DataExport::STATUS_COMPLETED) {
            throw ExportException::notReady();
        }

        if (! $export->isDownloadable() || ! $export->fileExists()) {
            throw ExportException::fileUnavailable();
        }
    }
}
