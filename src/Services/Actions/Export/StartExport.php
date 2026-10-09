<?php

declare(strict_types=1);

namespace Alif\Export\Services\Actions\Export;

use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportBuilder;
use Alif\Export\Helpers\ExportConfig;
use Alif\Export\Helpers\ExportPlan;
use Alif\Export\Helpers\ExportQuota;
use Alif\Export\Helpers\ExportRegistry;
use Alif\Export\Jobs\RunExport;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class StartExport
{
    public function __construct(
        private ExportRegistry $registry,
        private ExportBuilder $builder,
        private ExportQuota $quota,
    ) {}

    /**
     * @throws ValidationException When the host filter or the row cap rejects the request.
     * @throws ExportException Unknown key, or too many active exports.
     */
    public function __invoke(Authenticatable $owner, ExportCreateDTO $dto): ExportTask
    {
        $ownerId = (string) $owner->getAuthIdentifier();
        $taskId = (string) Str::uuid();

        // Cheapest check first: a rejected request must not pay for the filter and the row count.
        $this->quota->reserve($ownerId, $taskId);

        try {
            $task = $this->task($taskId, $ownerId, $dto);
            RunExport::dispatch($task->toArray());

            return $task;
        } catch (Throwable $e) {
            $this->quota->release($ownerId, $taskId);

            throw $e;
        }
    }

    /** @throws ValidationException */
    private function task(string $taskId, string $ownerId, ExportCreateDTO $dto): ExportTask
    {
        $plan = ExportPlan::for($this->registry->get($dto->exportable), $dto);
        $query = $this->builder->query($plan);
        // Counted once: the same number is the progress denominator and the cap check.
        $rows = ExportConfig::bool('progress.enabled') ? $this->builder->countRows($plan, $query) : null;
        $this->builder->assertNotEmpty($query, $rows);
        $this->builder->assertWithinCap($plan, $query, $rows);

        return new ExportTask($taskId, $ownerId, App::getLocale(), $dto, $rows);
    }
}
