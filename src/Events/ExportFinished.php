<?php

declare(strict_types=1);

namespace Alif\Export\Events;

use Alif\Export\Entities\DataExport;
use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched once an export reaches `completed` or `failed`. Hosts listen to notify the owner. */
final class ExportFinished
{
    use Dispatchable;

    public function __construct(public readonly DataExport $export) {}
}
