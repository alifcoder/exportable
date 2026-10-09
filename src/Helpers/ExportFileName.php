<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Alif\Export\DTO\Export\ExportTask;
use Illuminate\Support\Str;

final class ExportFileName
{
    /** `{title slug}_{timestamp}.{ext}` */
    public static function for(ExportTask $task): string
    {
        $dto = $task->request;
        $slug = Str::slug($dto->title ?? $dto->exportable, '_') ?: 'export';

        return sprintf('%s_%s.%s', $slug, now()->format('Y-m-d_His'), $dto->format->extension());
    }
}
