<?php

declare(strict_types=1);

namespace Alif\Export\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * How far a running export is. Sent only when `export.progress.enabled` is on and the export is at least
 * `export.progress.min_rows` rows. It goes to the `export.{id}` private channel, which the host authorises.
 */
final class ExportProgressed implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly string $exportId,
        public readonly int $percent,
        public readonly int $rows,
        public readonly int $totalRows,
    ) {}

    /** The private channel this event is sent to. */
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('export.'.$this->exportId);
    }

    /** The event name the client listens for. */
    public function broadcastAs(): string
    {
        return 'export.progressed';
    }

    /**
     * The data sent to the client.
     *
     * @return array{id: string, percent: int, rows: int, total_rows: int}
     */
    public function broadcastWith(): array
    {
        return ['id' => $this->exportId, 'percent' => $this->percent, 'rows' => $this->rows, 'total_rows' => $this->totalRows];
    }
}
