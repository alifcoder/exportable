<?php

declare(strict_types=1);

namespace Alif\Export\DTO\Export;

use Alif\Export\Enums\ExportFormatEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A validated export, as `HandlesExport` hands it to the service: the list's ready query (filter applied, no
 * limit/offset, a stable ORDER BY), the resource that renders one row, the format, the resolved and typed columns, the
 * plain eager loads of the query and the document count (null when progress is off). The service turns it into a
 * queued {@see ExportCreateDTO}. Objects are not queued: the job only gets the prepared query.
 */
final readonly class ExportDTO
{
    /**
     * @param  Builder<*>  $query
     * @param  class-string<JsonResource>  $resource
     * @param  list<ExportColumn>  $columns
     * @param  list<string>  $relations
     */
    public function __construct(
        public Builder $query,
        public string $resource,
        public ExportFormatEnum $format,
        public array $columns,
        public array $relations,
        public ?int $totalRows,
    ) {}
}
