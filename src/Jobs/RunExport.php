<?php

declare(strict_types=1);

namespace Alif\Export\Jobs;

use Alif\Export\Contracts\ExportFileStore;
use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\DTO\Export\PreparedQuery;
use Alif\Export\DTO\Export\RowSource;
use Alif\Export\Enums\ExportFormatEnum;
use Alif\Export\Events\ExportFinished;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportConfig;
use Alif\Export\Services\Actions\Export\WriteExport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** The queued job that writes the export file, stores it and tells the host with `ExportFinished`. */
final class RunExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Operator-facing ExportException codes that must reach the exception handler. */
    private const array REPORTED_CODES = ['storage_write_failed', 'resource_failed', 'unsupported_eager_load'];

    public int $tries = 1;

    public int $timeout;

    /** @param array<string, mixed> $task An {@see ExportTask} as an array. */
    public function __construct(public readonly array $task)
    {
        $this->timeout = ExportConfig::int('queue.timeout');
        $this->onConnection(ExportConfig::nullable('queue.connection'));
        $this->onQueue(ExportConfig::nullable('queue.name'));
    }

    /** Writes the file to a temporary path, gives it to the file store, and removes it. A failure is announced, not thrown. */
    public function handle(WriteExport $write, ExportFileStore $store): void
    {
        $task = null;
        $local = false;

        try {
            $task = ExportTask::fromArray($this->task);
            $local = tempnam(sys_get_temp_dir(), 'export_') ?: throw ExportException::storageWriteFailed('cannot create a temporary file');
            $rows = $write($task, $local);
            $name = $task->id.'.'.$task->request->format->extension();
            $fileId = $store->put($task, $local, $name, $task->request->format->mimeType());
            $finished = new ExportFinished($task, $fileId, $name, $rows, null);
        } catch (Throwable $e) {
            $finished = $this->failure($task ?? $this->placeholder(), $e);
        } finally {
            if ($local !== false) {
                @unlink($local);
            }
        }

        $this->announce($finished);
    }

    /** Called by the queue when the worker is killed (timeout) or the job crashes outside handle(). */
    public function failed(Throwable $e): void
    {
        try {
            $task = ExportTask::fromArray($this->task);
        } catch (Throwable) {
            $task = $this->placeholder();
        }

        $this->announce($this->failure($task, $e));
    }

    /** Turns an error into a failed `ExportFinished` event. Unexpected errors are also reported. */
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
        // event is sent once.
        if (! Cache::add("export-finished:{$event->task->id}", 1, ExportConfig::int('queue.timeout') + ExportConfig::int('stale_margin_seconds'))) {
            return;
        }

        try {
            event($event);
        } catch (Throwable $listenerError) {
            report($listenerError);
        }
    }

    /**
     * A payload that cannot be read (queued by an older version) still has its id: the failure is announced for that
     * id, with an empty task around it.
     */
    private function placeholder(): ExportTask
    {
        $request = is_array($this->task['request'] ?? null) ? $this->task['request'] : [];
        $format = ExportFormatEnum::tryFrom((string) ($request['format'] ?? '')) ?? ExportFormatEnum::EXCEL;

        return new ExportTask(
            (string) ($this->task['id'] ?? ''),
            new ExportCreateDTO($format, [], new PreparedQuery(null, '', []), new RowSource(Model::class, JsonResource::class, [])),
            null,
        );
    }
}
