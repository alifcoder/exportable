<?php

declare(strict_types=1);

namespace Alif\Export\Entities;

use Alif\Export\Enums\ExportStatus;
use Alif\Export\Helpers\ExportFile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|int $owner_id
 * @property string $exportable
 * @property string $format
 * @property ExportStatus $status
 * @property array<string, mixed> $options
 * @property string $locale
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $file_name
 * @property int|null $rows_count
 * @property string|null $error_code
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class DataExport extends Model
{
    use HasUuids;
    use Prunable;

    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('export.table', 'data_exports');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ExportStatus::class,
            'options' => 'array',
            'rows_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Pending/processing rows that are stuck: a lost dispatch (pending, created before the pending cutoff) or a
     * worker that died mid-run (processing, started before the queue timeout plus margin).
     *
     * @param  Builder<static>  $query
     */
    public function scopeStale(Builder $query): void
    {
        $pendingCutoff = now()->subHours((int) config('export.stale_pending_hours', 24));
        $processingCutoff = now()->subSeconds(
            (int) config('export.queue.timeout', 1800) + (int) config('export.stale_margin_seconds', 600),
        );

        $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $p) => $p
                ->where('status', ExportStatus::PENDING->value)
                ->where('created_at', '<', $pendingCutoff))
            ->orWhere(fn (Builder $p) => $p
                ->where('status', ExportStatus::PROCESSING->value)
                ->whereRaw('coalesce(started_at, created_at) < ?', [$processingCutoff])));
    }

    /**
     * Pending/processing rows that are still live (not stale); these count toward the user's quota.
     *
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', ExportStatus::inFlightValues())
            ->whereNot(fn (Builder $q) => $q->stale());
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        $ttlCutoff = now()->subHours((int) config('export.ttl_hours', 24));

        return static::query()->where(fn (Builder $q) => $q
            ->where('expires_at', '<', now())
            ->orWhere(fn (Builder $failed) => $failed
                ->where('status', ExportStatus::FAILED->value)
                ->whereRaw('coalesce(finished_at, updated_at) < ?', [$ttlCutoff]))
            ->orWhere(fn (Builder $stuck) => $stuck->stale()));
    }

    protected function pruning(): void
    {
        app(ExportFile::class)->delete($this);
    }
}
