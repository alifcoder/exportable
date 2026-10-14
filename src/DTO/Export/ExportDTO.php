<?php

declare(strict_types=1);

namespace Alif\Export\DTO\Export;

use Alif\Export\Enums\ExportFormatEnum;
use Alif\Export\Helpers\ColumnResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A validated request to export, as the host hands it over: the list's ready query (filter applied, no limit/offset,
 * a stable ORDER BY), the resource that renders one row and the requested columns as the client sent them. The service checks the query and turns
 * the request into a queued {@see ExportCreateDTO}. Objects are not queued: the job only gets the prepared
 * query.
 */
final readonly class ExportDTO
{
    /**
     * @param  Builder<*>  $query
     * @param  class-string<JsonResource>  $resource
     * @param  array<string, mixed>  $columns  `key => heading`, and `list => {key => heading, ...}` nested; validated by {@see ColumnResolver}.
     */
    public function __construct(
        public Builder $query,
        public string $resource,
        public ExportFormatEnum $format,
        public array $columns,
    ) {}
}
