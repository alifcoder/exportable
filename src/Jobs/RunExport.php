<?php

declare(strict_types=1);

namespace Alif\Export\Jobs;

use Alif\Export\Contracts\ExportFileStore;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Events\ExportFinished;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportConfig;
use Alif\Export\Helpers\ExportFileName;
use Alif\Export\Helpers\ExportQuota;
use Alif\Export\Services\Actions\Export\WriteExport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class RunExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Operator-facing ExportException codes that must reach the exception handler. */
    private const array REPORTED_CODES = ['owner_missing', 'storage_write_failed'];

    public int $tries = 1;

    public int $timeout;

    /** @param array<string, mixed> $task An {@see ExportTask} as an array. */
    public function __construct(public readonly array $task)
    {
        $this->timeout = ExportConfig::int('queue.timeout');
        $this->onConnection(ExportConfig::nullable('queue.connection'));
        $this->onQueue(ExportConfig::nullable('queue.name'));
    }

    public function handle(WriteExport $write, ExportFileStore $store, ExportQuota $quota): void
    {
        $task = ExportTask::fromArray($this->task);
        $local = false;

        try {
            $local = tempnam(sys_get_temp_dir(), 'export_') ?: throw ExportException::storageWriteFailed('cannot create a temporary file');
            $rows = $write($task, $local);
            $name = ExportFileName::for($task);
            $fileId = $store->put($task, $local, $name, $task->request->format->mimeType());
            $finished = new ExportFinished($task, $fileId, $name, $rows, null);
        } catch (Throwable $e) {
            $finished = $this->failure($task, $e);
        } finally {
            if ($local !== false) {
                @unlink($local);
            }
            $quota->release($task->ownerId, $task->id);
        }

        $this->announce($finished);
    }

    /** Called by the queue when the worker is killed (timeout) or the job crashes outside handle(). */
    public function failed(Throwable $e): void
    {
        $task = ExportTask::fromArray($this->task);

        app(ExportQuota::class)->release($task->ownerId, $task->id);
        $this->announce($this->failure($task, $e));
    }

    private function failure(ExportTask $task, Throwable $e): ExportFinished
    {
        if (! $e instanceof ExportException || in_array($e->errorCode, self::REPORTED_CODES, true)) {
            report($e);
        }

        return new ExportFinished($task, null, null, null, $e instanceof ExportException ? $e->errorCode : 'export_failed');
    }

    /** A listener that throws (mail down, ...) must never fail the job. */
    private function announce(ExportFinished $event): void
    {
        if (! ExportConfig::bool('events.finished')) {
            return;
        }

        // handle() and failed() can both reach here for one task (a worker killed during the announcement); the
        // owner is told once.
        if (! Cache::add("export-finished:{$event->task->id}", 1, ExportConfig::int('queue.timeout') + ExportConfig::int('stale_margin_seconds'))) {
            return;
        }

        try {
            event($event);
        } catch (Throwable $listenerError) {
            report($listenerError);
        }
    }
}
