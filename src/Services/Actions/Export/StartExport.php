<?php

declare(strict_types=1);

namespace Alif\Export\Services\Actions\Export;

use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\DTO\Export\ExportRequest;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\DTO\Export\PreparedQuery;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportBuilder;
use Alif\Export\Helpers\ExportConfig;
use Alif\Export\Helpers\ExportQuota;
use Alif\Export\Jobs\RunExport;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class StartExport
{
    public function __construct(
        private ExportBuilder $builder,
        private ExportQuota $quota,
    ) {}

    /**
     * Everything is decided here, before the job exists: the columns are checked, the rows counted, the query
     * compiled. The job only reads the prepared SQL and writes the file.
     *
     * @throws ValidationException When the columns, the row cap or an empty result reject the request.
     * @throws ExportException Too many active exports.
     */
    public function __invoke(Authenticatable $owner, ExportRequest $request): ExportTask
    {
        $ownerId = (string) $owner->getAuthIdentifier();
        $taskId = (string) Str::uuid();

        // Cheapest check first: a rejected request must not pay for the sample and the row count.
        $this->quota->reserve($ownerId, $taskId);

        try {
            $task = $this->task($taskId, $ownerId, $request);
            RunExport::dispatch($task->toArray());

            return $task;
        } catch (Throwable $e) {
            $this->quota->release($ownerId, $taskId);

            throw $e;
        }
    }

    /** @throws ValidationException */
    private function task(string $taskId, string $ownerId, ExportRequest $request): ExportTask
    {
        $query = $this->builder->ordered($request->query);
        // Before anything runs a query that names the relation.
        $this->builder->assertChildRelation($request, $query);
        // Counted once: the same number is the progress denominator and the cap check.
        $rows = ExportConfig::bool('progress.enabled') ? $this->builder->countRows($query, $request->childRelation) : null;
        $this->builder->assertNotEmpty($query, $rows);
        $this->builder->assertWithinCap($request->format, $request->childRelation, $query, $rows);
        [$columns, $childColumns] = $this->builder->describe($request, $query);

        $dto = new ExportCreateDTO(
            $request->source,
            $request->format,
            $columns,
            $request->childRelation,
            $childColumns,
            PreparedQuery::from($query),
        );

        return new ExportTask($taskId, $ownerId, App::getLocale(), $dto, $rows);
    }
}
