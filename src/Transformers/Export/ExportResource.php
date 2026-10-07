<?php

declare(strict_types=1);

namespace Alif\Export\Transformers\Export;

use Alif\Export\Entities\DataExport;
use Alif\Export\Helpers\ExportFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property DataExport $resource */
final class ExportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $export = $this->resource;

        return [
            'id' => $export->id,
            'exportable' => $export->exportable,
            'format' => $export->format,
            'status' => $export->status->value,
            'rows_count' => $export->rows_count,
            'error_code' => $export->error_code,
            'created_at' => $export->created_at->toIso8601String(),
            'finished_at' => $export->finished_at?->toIso8601String(),
            'expires_at' => $export->expires_at?->toIso8601String(),
            'download_url' => app(ExportFile::class)->isDownloadable($export) ? route('export.download', ['export' => $export->id]) : null,
        ];
    }
}
