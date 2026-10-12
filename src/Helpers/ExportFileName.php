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

        return sprintf('%s_%s.%s', Str::slug(Str::snake(self::name($dto->source)), '_') ?: 'export', now()->format('Y-m-d_His'), $dto->format->extension());
    }

    /** The name of the list, also the xlsx sheet name: `Modules\Sale\Http\Filters\SaleFilter` => `Sale`. */
    public static function name(string $source): string
    {
        return Str::headline(Str::beforeLast(class_basename($source), 'Filter'));
    }
}
