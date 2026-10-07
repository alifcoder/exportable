<?php

declare(strict_types=1);

namespace Alif\Export\Services;

use Alif\Export\Contracts\Exportable;
use Alif\Export\Contracts\ExportAuth;
use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\DTO\Export\ExportDownloadDTO;
use Alif\Export\DTO\Export\ExportListDTO;
use Alif\Export\Entities\DataExport;
use Alif\Export\Enums\ExportStatus;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportFile;
use Alif\Export\Helpers\ExportRegistry;
use Alif\Export\Services\Actions\Export\DeleteExport;
use Alif\Export\Services\Actions\Export\RetryExport;
use Alif\Export\Services\Actions\Export\StartExport;
use Alif\Export\Services\Interfaces\ExportServiceInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\Paginator;

final readonly class ExportService implements ExportServiceInterface
{
    public function __construct(
        private ExportRegistry $registry,
        private ExportAuth $auth,
        private StartExport $startExport,
        private RetryExport $retryExport,
        private DeleteExport $deleteExport,
        private ExportFile $files,
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
            ->when($dto->status !== null, fn ($q) => $q->where('status', $dto->status->value))
            ->when($dto->exportable !== null, fn ($q) => $q->where('exportable', $dto->exportable))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->simplePaginate($dto->perPage);
    }

    public function find(Authenticatable $owner, string $id): DataExport
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

    public function retry(Authenticatable $owner, string $id): DataExport
    {
        return ($this->retryExport)($owner, $this->find($owner, $id));
    }

    public function delete(Authenticatable $owner, string $id): void
    {
        ($this->deleteExport)($this->find($owner, $id));
    }

    public function download(Authenticatable $owner, string $id): ExportDownloadDTO
    {
        $export = $this->find($owner, $id);

        if (! $this->auth->allows($owner, $export->exportable)) {
            throw ExportException::forbidden();
        }

        if ($export->status !== ExportStatus::COMPLETED) {
            throw ExportException::notReady();
        }

        if (! $this->files->isDownloadable($export) || ! $this->files->exists($export)) {
            throw ExportException::fileUnavailable();
        }

        return new ExportDownloadDTO(
            disk: (string) $export->disk,
            path: (string) $export->path,
            fileName: (string) $export->file_name,
            mimeType: ExportCreateDTO::fromArray($export->options)->format->mimeType(),
        );
    }
}
