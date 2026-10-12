<?php

declare(strict_types=1);

namespace Alif\Export\Services\Actions\Export;

use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\DTO\Export\ExportRequest;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\DTO\Export\PreparedQuery;
use Alif\Export\Helpers\ExportBuilder;
use Alif\Export\Helpers\ExportConfig;
use Alif\Export\Jobs\RunExport;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class StartExport
{
    public function __construct(private ExportBuilder $builder) {}

    /**
     * Everything is decided here, before the job exists: the columns are checked, the rows counted, the query
     * compiled. The job only reads the prepared SQL and writes the file.
     *
     * @throws ValidationException When the columns, the row cap or an empty result reject the request.
     */
    public function __invoke(ExportRequest $request): ExportTask
    {
        $query = $this->builder->ordered($request->query);
        // Counted once: the same number is the progress denominator and the cap check.
        $rows = ExportConfig::bool('progress.enabled') ? $this->builder->countRows($query) : null;
        $this->builder->assertNotEmpty($query, $rows);
        $this->builder->assertWithinCap($request->format, $query, $rows);

        $dto = new ExportCreateDTO(
            $request->source,
            $request->format,
            $this->builder->describe($request, $query),
            PreparedQuery::from($query),
        );

        $task = new ExportTask((string) Str::uuid(), App::getLocale(), $dto, $rows);
        RunExport::dispatch($task->toArray());

        return $task;
    }
}
