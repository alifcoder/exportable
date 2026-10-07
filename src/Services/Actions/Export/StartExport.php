<?php

declare(strict_types=1);

namespace Alif\Export\Services\Actions\Export;

use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\Entities\DataExport;
use Alif\Export\Enums\ExportStatus;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportBuilder;
use Alif\Export\Helpers\ExportPlan;
use Alif\Export\Helpers\ExportRegistry;
use Alif\Export\Jobs\RunExport;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class StartExport
{
    public function __construct(
        private ExportRegistry $registry,
        private ExportBuilder $builder,
        private FailExport $failExport,
    ) {}

    /**
     * Needs a cache store with atomic locks (redis, database, file, memcached, array): the per-owner lock makes the
     * quota check and the insert one step, so parallel requests cannot exceed the limit.
     *
     * @throws ValidationException When the host filter or the row cap rejects the request.
     * @throws ExportException Too many active exports.
     */
    public function __invoke(Authenticatable $owner, ExportCreateDTO $dto): DataExport
    {
        $ownerId = (string) $owner->getAuthIdentifier();

        try {
            return Cache::lock("export-start:{$ownerId}", 30)->block(10, fn (): DataExport => $this->start($ownerId, $dto));
        } catch (LockTimeoutException) {
            throw ExportException::tooManyActive();
        }
    }

    private function start(string $ownerId, ExportCreateDTO $dto): DataExport
    {
        // Cheapest check first: a rejected request must not pay for the filter and the row count.
        $active = DataExport::query()->where('owner_id', $ownerId)->active()->count();
        if ($active >= (int) config('export.max_active_per_user', 3)) {
            throw ExportException::tooManyActive();
        }

        $plan = ExportPlan::for($this->registry->get($dto->exportable), $dto);
        $this->builder->assertWithinCap($plan, $this->builder->query($plan));

        $export = DataExport::query()->create([
            'owner_id' => $ownerId,
            'exportable' => $dto->exportable,
            'format' => $dto->format->value,
            'status' => ExportStatus::PENDING,
            'options' => $dto->toArray(),
            'locale' => App::getLocale(),
        ]);

        try {
            RunExport::dispatch($export->id);
        } catch (Throwable $e) {
            ($this->failExport)($export, 'export_dispatch_failed');

            throw $e;
        }

        return $export;
    }
}
