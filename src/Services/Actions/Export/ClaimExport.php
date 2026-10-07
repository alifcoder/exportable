<?php

declare(strict_types=1);

namespace Alif\Export\Services\Actions\Export;

use Alif\Export\Entities\DataExport;
use Alif\Export\Enums\ExportStatus;

/**
 * Atomically moves pending → processing and records where the file will go, so pruning can delete the file of a
 * crashed export.
 */
final readonly class ClaimExport
{
    /** @return bool False when another delivery already claimed the export. */
    public function __invoke(DataExport $export, string $disk, string $path): bool
    {
        $claimed = DataExport::query()
            ->whereKey($export->getKey())
            ->where('status', ExportStatus::PENDING->value)
            ->update([
                'status' => ExportStatus::PROCESSING->value,
                'started_at' => now(),
                'disk' => $disk,
                'path' => $path,
            ]);

        if ($claimed === 0) {
            return false;
        }

        $export->refresh();

        return true;
    }
}
