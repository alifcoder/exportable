<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Alif\Export\Entities\DataExport;
use Alif\Export\Enums\ExportStatus;
use Illuminate\Support\Facades\Storage;

/** Answers questions about the file an export points to, and removes it. */
final class ExportFile
{
    public function isExpired(DataExport $export): bool
    {
        return $export->expires_at !== null && $export->expires_at->isPast();
    }

    /** Completed, not expired and a file recorded. Cheap: does not touch the storage disk. */
    public function isDownloadable(DataExport $export): bool
    {
        return $export->status === ExportStatus::COMPLETED
            && ! $this->isExpired($export)
            && $export->disk !== null
            && $export->path !== null;
    }

    public function exists(DataExport $export): bool
    {
        return $export->disk !== null && $export->path !== null && Storage::disk($export->disk)->exists($export->path);
    }

    public function delete(DataExport $export): void
    {
        if ($export->disk !== null && $export->path !== null) {
            Storage::disk($export->disk)->delete($export->path);
        }
    }
}
