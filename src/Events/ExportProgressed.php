<?php

declare(strict_types=1);

namespace Alif\Export\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * How far a running export is, for the owner's client. Sent only when `export.progress.enabled` is on and the
 * export is at least `export.progress.min_rows` rows. The host authorises the `exports.{ownerId}` private channel.
 */
final class ExportProgressed implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly string $exportId,
        public readonly string $ownerId,
        public readonly int $percent,
        public readonly int $rows,
        public readonly int $totalRows,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('exports.'.$this->ownerId);
    }

    public function broadcastAs(): string
    {
        return 'export.progressed';
    }

    /** @return array{id: string, percent: int, rows: int, total_rows: int} */
    public function broadcastWith(): array
    {
        return ['id' => $this->exportId, 'percent' => $this->percent, 'rows' => $this->rows, 'total_rows' => $this->totalRows];
    }
}
