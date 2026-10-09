<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Concerns;

use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Enums\ExportFormat;
use Alif\Export\Tests\Fixtures\User;

trait MakesTasks
{
    /** @param list<string> $columns */
    protected function makeTask(User $owner, array $columns = ['number'], ExportFormat $format = ExportFormat::CSV, ?int $total = null, bool $children = false): ExportTask
    {
        return new ExportTask(
            id: '00000000-0000-4000-8000-000000000001',
            ownerId: (string) $owner->getKey(),
            locale: 'en',
            request: new ExportCreateDTO('orders', $format, $columns, $children, $children ? ['sku', 'qty'] : [], null, []),
            totalRows: $total,
        );
    }
}
