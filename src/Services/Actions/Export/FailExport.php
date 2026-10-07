<?php

declare(strict_types=1);

namespace Alif\Export\Services\Actions\Export;

use Alif\Export\Entities\DataExport;
use Alif\Export\Enums\ExportStatus;

final readonly class FailExport
{
    public function __invoke(DataExport $export, string $errorCode): void
    {
        $export->update([
            'status' => ExportStatus::FAILED,
            'error_code' => $errorCode,
            'finished_at' => now(),
        ]);
    }
}
