<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Alif\Export\Exceptions\ExportException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Counts an owner's pending and processing exports in the cache. Every export holds its own slot key
 * (`export-slot:{owner}:{task}`) that expires `queue.timeout + stale_margin_seconds` after it was taken, so a slot
 * lost with a killed worker frees itself without touching any other slot. Releasing deletes that one key: it is
 * idempotent, takes no lock, and cannot be lost, which also makes it safe with a synchronous queue.
 *
 * The owner's lock is held only while the slots are counted and one is added; slow work happens outside it.
 * Needs a cache store with atomic locks (redis, database, file, memcached, array).
 */
final class ExportQuota
{
    private const int LOCK_SECONDS = 10;

    private const int LOCK_WAIT_SECONDS = 5;

    /** @throws ExportException Over the limit, or the lock could not be taken. */
    public function reserve(string $ownerId, string $taskId): void
    {
        try {
            Cache::lock("export-quota:{$ownerId}", self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, function () use ($ownerId, $taskId): void {
                $live = array_values(array_filter(
                    (array) Cache::get($this->indexKey($ownerId), []),
                    fn (string $id): bool => Cache::has($this->slotKey($ownerId, $id)),
                ));

                if (count($live) >= ExportConfig::int('max_active_per_user')) {
                    throw ExportException::tooManyActive();
                }

                Cache::put($this->slotKey($ownerId, $taskId), 1, $this->ttl());
                Cache::put($this->indexKey($ownerId), [...$live, $taskId], $this->ttl());
            });
        } catch (LockTimeoutException) {
            throw ExportException::tooManyActive();
        }
    }

    /** Gives the slot back; calling it again, or for a slot that already expired, does nothing. */
    public function release(string $ownerId, string $taskId): void
    {
        Cache::forget($this->slotKey($ownerId, $taskId));
    }

    private function ttl(): int
    {
        return ExportConfig::int('queue.timeout') + ExportConfig::int('stale_margin_seconds');
    }

    private function slotKey(string $ownerId, string $taskId): string
    {
        return "export-slot:{$ownerId}:{$taskId}";
    }

    private function indexKey(string $ownerId): string
    {
        return "export-slots:{$ownerId}";
    }
}
