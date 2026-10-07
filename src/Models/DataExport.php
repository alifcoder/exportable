<?php

declare(strict_types=1);

namespace Alif\Export\Models;

use Alif\Export\ExportOptions;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string|int $owner_id
 * @property string $exportable
 * @property string $format
 * @property string $status
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

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_PROCESSING = 'processing';

    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_FAILED = 'failed';

    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('export.table', 'data_exports');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'rows_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function exportOptions(): ExportOptions
    {
        return ExportOptions::fromArray($this->options);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Completed, not expired and a file recorded. Cheap: does not touch the storage disk. */
    public function isDownloadable(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            && ! $this->isExpired()
            && $this->disk !== null
            && $this->path !== null;
    }

    public function fileExists(): bool
    {
        return $this->disk !== null && $this->path !== null && Storage::disk($this->disk)->exists($this->path);
    }

    /**
     * Atomically move pending → processing and record where the file will go, so pruning can delete the file of a
     * crashed export. False when another delivery already claimed it.
     */
    public function claim(string $disk, string $path): bool
    {
        $claimed = static::query()
            ->whereKey($this->getKey())
            ->where('status', self::STATUS_PENDING)
            ->update(['status' => self::STATUS_PROCESSING, 'started_at' => now(), 'disk' => $disk, 'path' => $path]);

        if ($claimed === 0) {
            return false;
        }

        $this->refresh();

        return true;
    }

    public function markCompleted(int $rows): void
    {
        $options = $this->exportOptions();
        $slug = Str::slug($options->title ?? $options->exportable, '_') ?: 'export';

        $this->update([
            'status' => self::STATUS_COMPLETED,
            'file_name' => sprintf('%s_%s.%s', $slug, now()->format('Y-m-d_His'), $options->format->extension()),
            'rows_count' => $rows,
            'finished_at' => now(),
            'expires_at' => now()->addHours((int) config('export.ttl_hours', 24)),
        ]);
    }

    public function markFailed(string $errorCode): void
    {
        $this->update(['status' => self::STATUS_FAILED, 'error_code' => $errorCode, 'finished_at' => now()]);
    }

    /** True while the row can still move on its own (a worker may be about to claim or finish it). */
    public function isInFlight(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true);
    }

    /**
     * Atomically delete the row unless a live worker is processing it. Pending rows are deleted too: a worker that
     * later finds no row does nothing.
     */
    public function deleteIfIdle(): bool
    {
        return static::query()
            ->whereKey($this->getKey())
            ->where(fn (Builder $q) => $q
                ->where('status', '!=', self::STATUS_PROCESSING)
                ->orWhere(fn (Builder $stale) => $stale->stale()))
            ->delete() > 0;
    }

    /** A processing row is stuck when its worker started it before this moment (worker died). */
    public static function staleCutoff(): CarbonInterface
    {
        $seconds = (int) config('export.queue.timeout', 1800) + (int) config('export.stale_margin_seconds', 600);

        return now()->subSeconds($seconds);
    }

    /** A pending row is stuck when it was created before this moment (dispatch lost). */
    public static function pendingCutoff(): CarbonInterface
    {
        return now()->subHours((int) config('export.stale_pending_hours', 24));
    }

    /**
     * Pending/processing rows that are stuck: a lost dispatch, or a worker that died mid-run.
     *
     * @param  Builder<static>  $query
     */
    public function scopeStale(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $p) => $p
                ->where('status', self::STATUS_PENDING)
                ->where('created_at', '<', self::pendingCutoff()))
            ->orWhere(fn (Builder $p) => $p
                ->where('status', self::STATUS_PROCESSING)
                ->whereRaw('coalesce(started_at, created_at) < ?', [self::staleCutoff()])));
    }

    /**
     * Pending/processing rows that are still live (not stale); these count toward the user's quota.
     *
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_PROCESSING])
            ->whereNot(fn (Builder $q) => $q->stale());
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        $ttlCutoff = now()->subHours((int) config('export.ttl_hours', 24));

        return static::query()->where(fn (Builder $q) => $q
            ->where('expires_at', '<', now())
            ->orWhere(fn (Builder $failed) => $failed
                ->where('status', self::STATUS_FAILED)
                ->whereRaw('coalesce(finished_at, updated_at) < ?', [$ttlCutoff]))
            ->orWhere(fn (Builder $stuck) => $stuck->stale()));
    }

    protected function pruning(): void
    {
        if ($this->disk !== null && $this->path !== null) {
            Storage::disk($this->disk)->delete($this->path);
        }
    }
}
