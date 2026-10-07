<?php

declare(strict_types=1);

namespace Alif\Export\Services\Actions\Export;

use Alif\Export\Entities\DataExport;
use Alif\Export\Enums\ExportStatus;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportFile;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cancels a pending export or deletes a finished one together with its file. Pending rows are deleted too: a worker
 * that later finds no row does nothing. A stuck processing row can be deleted; a live one cannot.
 */
final readonly class DeleteExport
{
    public function __construct(private ExportFile $files) {}

    /** @throws ExportException Being processed. */
    public function __invoke(DataExport $export): void
    {
        $deleted = DataExport::query()
            ->whereKey($export->getKey())
            ->where(fn (Builder $q) => $q
                ->where('status', '!=', ExportStatus::PROCESSING->value)
                ->orWhere(fn (Builder $stale) => $stale->stale()))
            ->delete();

        if ($deleted === 0) {
            throw ExportException::beingProcessed();
        }

        $this->files->delete($export);
    }
}
