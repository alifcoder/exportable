<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Alif\Export\DTO\Export\ExportTask;
use Illuminate\Support\Str;

final class ExportFileName
{
    /** `{source name}_{timestamp}.{ext}`: `SaleFilter` => `sale_2026-03-04_050607.xlsx` */
    public static function for(ExportTask $task): string
    {
        $dto = $task->request;

        $name = Str::headline(Str::beforeLast(class_basename($dto->source), 'Filter'));

        return sprintf('%s_%s.%s', Str::slug(Str::snake($name), '_') ?: 'export', now()->format('Y-m-d_His'), $dto->format->extension());
    }
}
