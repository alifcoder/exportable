<?php

declare(strict_types=1);

namespace Alif\Export\Events;

use Alif\Export\Enums\ExportPhaseEnum;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * How far an export is. Sent for every export when `export.progress.enabled` is on. It goes to the `export.{id}`
 * private channel, which the host authorises. `percent` and `totalRows` are null when the total is unknown.
 */
final class ExportProgressed implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly string $exportId,
        public readonly ExportPhaseEnum $phase,
        public readonly ?int $percent,
        public readonly int $rows,
        public readonly ?int $totalRows,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('export.'.$this->exportId);
    }

    public function broadcastAs(): string
    {
        return 'export.progressed';
    }

    /** @return array{id: string, percent: int|null, rows: int, total_rows: int|null, phase: string} */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->exportId,
            'percent' => $this->percent,
            'rows' => $this->rows,
            'total_rows' => $this->totalRows,
            'phase' => $this->phase->value,
        ];
    }
}
