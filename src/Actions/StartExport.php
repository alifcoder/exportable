<?php

declare(strict_types=1);

namespace Alif\Export\Actions;

use Alif\Export\ExportBuilder;
use Alif\Export\ExportOptions;
use Alif\Export\ExportPlan;
use Alif\Export\ExportRegistry;
use Alif\Export\Jobs\RunExport;
use Alif\Export\Models\DataExport;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
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
     * Needs a cache store with atomic locks (redis, database, file, memcached, array): the per-owner lock makes the
     * quota check and the insert one step, so parallel requests cannot exceed the limit.
     *
     * @throws ValidationException When the host filter or the row cap rejects the request.
     * @throws HttpException 429 when too many exports are active.
     */
    public function handle(Authenticatable $owner, ExportOptions $options): DataExport
    {
        $ownerId = (string) $owner->getAuthIdentifier();

        try {
            return Cache::lock("export-start:{$ownerId}", 30)->block(10, fn (): DataExport => $this->start($ownerId, $options));
        } catch (LockTimeoutException) {
            abort(429, 'Too many active exports.');
        }
    }

    private function start(string $ownerId, ExportOptions $options): DataExport
    {
        // Cheapest check first: a rejected request must not pay for the filter and the row count.
        $active = DataExport::query()->where('owner_id', $ownerId)->active()->count();
        abort_if($active >= (int) config('export.max_active_per_user', 3), 429, 'Too many active exports.');

        $plan = ExportPlan::for($this->registry->get($options->exportable), $options);
        $this->builder->assertWithinCap($plan, $this->builder->query($plan));

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
            $export->markFailed('export_dispatch_failed');

            throw $e;
        }

        return $export;
    }
}
