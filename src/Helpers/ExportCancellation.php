<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Alif\Export\Exceptions\ExportException;
use Illuminate\Support\Facades\Cache;

/**
 * The cancel flag of an export. The host sets it; the job checks it when it starts, before every chunk and around
 * the upload. It is kept as long as the job may wait and run (`queue.timeout` + `stale_margin_seconds`).
 */
final class ExportCancellation
{
    public static function cancel(string $exportId): void
    {
        Cache::put(self::key($exportId), true, ExportConfig::int('queue.timeout') + ExportConfig::int('stale_margin_seconds'));
    }

    public static function requested(string $exportId): bool
    {
        return Cache::has(self::key($exportId));
    }

    /** @throws ExportException cancelled */
    public static function check(string $exportId): void
    {
        if (self::requested($exportId)) {
            throw ExportException::cancelled();
        }
    }

    private static function key(string $exportId): string
    {
        return "export:$exportId:cancelled";
    }
}
