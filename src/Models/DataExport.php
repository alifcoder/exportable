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

    public function isDownloadable(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            && ! $this->isExpired()
            && $this->disk !== null
            && $this->path !== null
            && Storage::disk($this->disk)->exists($this->path);
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
     * Pending/processing rows that are still live (not stale).
     *
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $p) => $p
                ->where('status', self::STATUS_PENDING)
                ->where('created_at', '>=', self::pendingCutoff()))
            ->orWhere(fn (Builder $p) => $p
                ->where('status', self::STATUS_PROCESSING)
                ->where(fn (Builder $s) => $s
                    ->where('started_at', '>=', self::staleCutoff())
                    ->orWhere(fn (Builder $n) => $n
                        ->whereNull('started_at')
                        ->where('created_at', '>=', self::staleCutoff())))));
    }

    /**
     * Pending/processing rows that are stuck.
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
                ->where(fn (Builder $s) => $s
                    ->where('started_at', '<', self::staleCutoff())
                    ->orWhere(fn (Builder $n) => $n
                        ->whereNull('started_at')
                        ->where('created_at', '<', self::staleCutoff())))));
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        $ttlCutoff = now()->subHours((int) config('export.ttl_hours', 24));

        return static::query()->where(fn (Builder $q) => $q
            ->where('expires_at', '<', now())
            ->orWhere(fn (Builder $f) => $f
                ->where('status', self::STATUS_FAILED)
                ->where(fn (Builder $t) => $t
                    ->where('finished_at', '<', $ttlCutoff)
                    ->orWhere(fn (Builder $n) => $n
                        ->whereNull('finished_at')
                        ->where('updated_at', '<', $ttlCutoff))))
            ->orWhere(fn (Builder $f) => $f->stale()));
    }

    protected function pruning(): void
    {
        if ($this->disk !== null && $this->path !== null) {
            Storage::disk($this->disk)->delete($this->path);
        }
    }
}
