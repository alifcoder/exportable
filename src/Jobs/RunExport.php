<?php

declare(strict_types=1);

namespace Alif\Export\Jobs;

use Alif\Export\Contracts\ExportAuth;
use Alif\Export\Events\ExportFinished;
use Alif\Export\ExportBuilder;
use Alif\Export\ExportException;
use Alif\Export\ExportRegistry;
use Alif\Export\ExportWriter;
use Alif\Export\Models\DataExport;
use Alif\Export\Support\QueryFilterCompatibility;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class RunExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Operator-facing ExportException codes that must reach the exception handler. */
    private const array REPORTED_CODES = ['incompatible_query_filter', 'owner_missing'];

    public int $tries = 1;

    public int $timeout;

    public function __construct(public readonly string $exportId)
    {
        $this->timeout = (int) config('export.queue.timeout', 1800);
        $this->onConnection(config('export.queue.connection'));
        $this->onQueue(config('export.queue.name'));
    }

    public function handle(ExportAuth $auth, ExportRegistry $registry, ExportBuilder $builder, ExportWriter $writer): void
    {
        $export = DataExport::query()->find($this->exportId);

        if ($export === null || $export->status !== DataExport::STATUS_PENDING) {
            return;
        }

        $disk = (string) (config('export.disk') ?? config('filesystems.default'));
        $path = sprintf('%s/%s.%s', trim((string) config('export.directory', 'exports'), '/'), Str::uuid(), $export->format);

        // Disk/path are stored on claim so pruning can delete the file of a crashed export.
        // Conditional transition: a redelivered job must not run the export twice.
        $claimed = DataExport::query()
            ->whereKey($export->getKey())
            ->where('status', DataExport::STATUS_PENDING)
            ->update(['status' => DataExport::STATUS_PROCESSING, 'started_at' => now(), 'disk' => $disk, 'path' => $path]);

        if ($claimed === 0) {
            return;
        }

        $export->refresh();

        $previousLocale = App::getLocale();

        try {
            QueryFilterCompatibility::assertCompatible();
            App::setLocale($export->locale);
            $rows = $auth->actingAs($export->owner_id, function (Authenticatable $owner) use ($auth, $registry, $builder, $writer, $export, $disk, $path): int {
                $options = $export->exportOptions();

                if (! $auth->allows($owner, $options->exportable)) {
                    throw ExportException::forbidden();
                }

                $exportable = $registry->get($options->exportable);
                $previous = Model::preventsLazyLoading();
                Model::preventLazyLoading();

                try {
                    return $writer->store(
                        $options->format,
                        $options->title ?? (string) __($exportable->title()),
                        $builder->headings($exportable, $options),
                        $builder->numericMap($exportable, $options),
                        $builder->rows($exportable, $options, $builder->query($exportable, $options)),
                        $disk,
                        $path,
                    );
                } finally {
                    Model::preventLazyLoading($previous);
                }
            });

            $export->update([
                'status' => DataExport::STATUS_COMPLETED,
                'disk' => $disk,
                'path' => $path,
                'file_name' => $this->fileName($export),
                'rows_count' => $rows,
                'finished_at' => now(),
                'expires_at' => now()->addHours((int) config('export.ttl_hours', 24)),
            ]);
            ExportFinished::dispatch($export);
        } catch (Throwable $e) {
            Storage::disk($disk)->delete($path);
            $this->markFailed($export, $e);

            if (! $e instanceof ExportException || in_array($e->errorCode, self::REPORTED_CODES, true)) {
                report($e);
            }
        } finally {
            App::setLocale($previousLocale);
        }
    }

    public function failed(Throwable $e): void
    {
        $export = DataExport::query()->find($this->exportId);

        if ($export !== null && in_array($export->status, [DataExport::STATUS_PENDING, DataExport::STATUS_PROCESSING], true)) {
            $this->markFailed($export, $e);
        }
    }

    private function markFailed(DataExport $export, Throwable $e): void
    {
        $export->update([
            'status' => DataExport::STATUS_FAILED,
            'error_code' => $e instanceof ExportException ? $e->errorCode : 'export_failed',
            'finished_at' => now(),
        ]);
        ExportFinished::dispatch($export);
    }

    private function fileName(DataExport $export): string
    {
        $options = $export->exportOptions();
        $slug = Str::slug($options->title ?? $options->exportable, '_') ?: 'export';

        return sprintf('%s_%s.%s', $slug, now()->format('Y-m-d_His'), $options->format->extension());
    }
}
