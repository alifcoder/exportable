<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Concerns;

use Alif\Export\Entities\DataExport;
use Alif\Export\Enums\ExportStatus;
use Alif\Export\Tests\Fixtures\User;

/** Shared row factory for tests that need an export in a given state without running the job. */
trait MakesExports
{
    /** @param array<string, mixed> $attrs */
    protected function makeExport(User $owner, array $attrs = []): DataExport
    {
        $options = ($attrs['options'] ?? []) + [
            'exportable' => 'orders',
            'format' => 'csv',
            'columns' => ['number'],
            'include_children' => false,
            'child_columns' => [],
            'title' => null,
            'parameters' => [],
        ];
        unset($attrs['options']);

        return DataExport::query()->create($attrs + [
            'owner_id' => (string) $owner->getKey(),
            'exportable' => $options['exportable'],
            'format' => $options['format'],
            'status' => ExportStatus::PENDING,
            'options' => $options,
            'locale' => 'en',
        ])->refresh();
    }
}
