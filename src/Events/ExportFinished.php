<?php

declare(strict_types=1);

namespace Alif\Export\Events;

use Alif\Export\DTO\Export\ExportTask;
use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched once an export is written or has failed (when `export.events.finished` is on). Hosts listen to notify the owner. */
final class ExportFinished
{
    use Dispatchable;

    public function __construct(
        public readonly ExportTask $task,
        public readonly ?string $fileId,
        public readonly ?string $fileName,
        public readonly ?int $rows,
        public readonly ?string $errorCode,
    ) {}

    public function succeeded(): bool
    {
        return $this->errorCode === null;
    }
}
