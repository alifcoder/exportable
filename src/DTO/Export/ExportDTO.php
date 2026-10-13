<?php

declare(strict_types=1);

namespace Alif\Export\DTO\Export;

use Alif\Export\Contracts\ExportableFilter;
use Alif\Export\Enums\ExportFormatEnum;

/**
 * A validated request to export, as the host hands it over: the filter that serves the list (it provides the ready
 * query and the resource) and the requested columns. The service asks the filter for its query once, checks it and turns
 * the request into a queued {@see ExportCreateDTO}. Objects are not queued: the job only gets the prepared
 * query and a {@see RowSource}.
 */
final readonly class ExportDTO
{
    /** @param list<ExportColumn> $columns */
    public function __construct(
        public ExportableFilter $source,
        public ExportFormatEnum $format,
        public array $columns,
    ) {}
}
