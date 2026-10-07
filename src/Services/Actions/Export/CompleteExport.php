<?php

declare(strict_types=1);

namespace Alif\Export\Services\Actions\Export;

use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\Entities\DataExport;
use Alif\Export\Enums\ExportStatus;
use Illuminate\Support\Str;

final readonly class CompleteExport
{
    public function __invoke(DataExport $export, int $rows): void
    {
        $dto = ExportCreateDTO::fromArray($export->options);
        $slug = Str::slug($dto->title ?? $dto->exportable, '_') ?: 'export';

        $export->update([
            'status' => ExportStatus::COMPLETED,
            'file_name' => sprintf('%s_%s.%s', $slug, now()->format('Y-m-d_His'), $dto->format->extension()),
            'rows_count' => $rows,
            'finished_at' => now(),
            'expires_at' => now()->addHours((int) config('export.ttl_hours', 24)),
        ]);
    }
}
