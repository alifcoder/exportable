<?php

declare(strict_types=1);

namespace Alif\Export\Jobs;

use Alif\Export\Entities\DataExport;
use Alif\Export\Events\ExportFinished;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Services\Actions\Export\ClaimExport;
use Alif\Export\Services\Actions\Export\CompleteExport;
use Alif\Export\Services\Actions\Export\FailExport;
use Alif\Export\Services\Actions\Export\GenerateExport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    public function __construct(public readonly string $exportId)
    {
        $this->timeout = (int) config('export.queue.timeout', 1800);
        $this->onConnection(config('export.queue.connection'));
        $this->onQueue(config('export.queue.name'));
    }

    public function handle(GenerateExport $generate, ClaimExport $claim, CompleteExport $complete, FailExport $fail): void
    {
        $export = DataExport::query()->find($this->exportId);

        if ($export === null) {
            return;
        }

        $disk = (string) (config('export.disk') ?? config('filesystems.default'));
        $path = sprintf('%s/%s.%s', trim((string) config('export.directory', 'exports'), '/'), Str::uuid(), $export->format);

        // A redelivered or already finished job must not run the export twice.
        if (! $claim($export, $disk, $path)) {
            return;
        }

        try {
            $complete($export, $generate($export));
        } catch (Throwable $e) {
            Storage::disk($disk)->delete($path);
            $this->recordFailure($export, $e, $fail);

            return;
        }

        $this->announce($export);
    }

    /** Called by the queue when the worker is killed (timeout) or the job crashes outside handle(). */
    public function failed(Throwable $e): void
    {
        $export = DataExport::query()->find($this->exportId);

        if ($export !== null && $export->status->isInFlight()) {
            $this->recordFailure($export, $e, app(FailExport::class));
        }
    }

    private function recordFailure(DataExport $export, Throwable $e, FailExport $fail): void
    {
        $fail($export, $e instanceof ExportException ? $e->errorCode : 'export_failed');

        if (! $e instanceof ExportException || in_array($e->errorCode, self::REPORTED_CODES, true)) {
            report($e);
        }

        $this->announce($export);
    }

    /** A listener that throws (mail down, ...) must never turn a finished export into a failed one. */
    private function announce(DataExport $export): void
    {
        try {
            ExportFinished::dispatch($export);
        } catch (Throwable $listenerError) {
            report($listenerError);
        }
    }
}
