<?php

declare(strict_types=1);

namespace Alif\Export\Services;

use Alif\Export\DTO\Export\ExportRequest;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Services\Actions\Export\StartExport;
use Alif\Export\Services\Interfaces\ExportServiceInterface;

final readonly class ExportService implements ExportServiceInterface
{
    public function __construct(private StartExport $startExport) {}

    public function create(ExportRequest $request): ExportTask
    {
        return ($this->startExport)($request);
    }
}
