<?php

declare(strict_types=1);

namespace Alif\Export\Actions;

use Alif\Export\ExportBuilder;
use Alif\Export\ExportOptions;
use Alif\Export\ExportRegistry;
use Alif\Export\Jobs\RunExport;
use Alif\Export\Models\DataExport;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class StartExport
{
    public function __construct(
        private readonly ExportRegistry $registry,
        private readonly ExportBuilder $builder,
    ) {}

    /**
     * @throws ValidationException When the host filter or the row cap rejects the request.
     * @throws HttpException 429 when too many exports are active.
     */
    public function handle(Authenticatable $owner, ExportOptions $options): DataExport
    {
        $query = $this->builder->query($this->registry->get($options->exportable), $options);
        $this->builder->assertWithinCap($query, $options->format);

        $ownerId = (string) $owner->getAuthIdentifier();

        $active = DataExport::query()
            ->where('owner_id', $ownerId)
            ->active()
            ->count();

        abort_if($active >= (int) config('export.max_active_per_user', 3), 429, 'Too many active exports.');

        $export = DataExport::query()->create([
            'owner_id' => $ownerId,
            'exportable' => $options->exportable,
            'format' => $options->format->value,
            'status' => DataExport::STATUS_PENDING,
            'options' => $options->toArray(),
            'locale' => App::getLocale(),
        ]);

        try {
            RunExport::dispatch($export->id);
        } catch (Throwable $e) {
            $export->update([
                'status' => DataExport::STATUS_FAILED,
                'error_code' => 'export_dispatch_failed',
                'finished_at' => now(),
            ]);

            throw $e;
        }

        return $export;
    }
}
