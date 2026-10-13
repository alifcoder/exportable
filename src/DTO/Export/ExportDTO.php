<?php

declare(strict_types=1);

namespace Alif\Export\DTO\Export;

use Alif\Export\Contracts\ExportSource;
use Alif\Export\Enums\ExportFormatEnum;
use Illuminate\Database\Eloquent\Builder;

/**
 * A validated request to export, as the host hands it over: the list's applied query (not yet run) and the
 * requested columns. The service turns it into a queued {@see ExportCreateDTO}.
 */
final readonly class ExportDTO
{
    /**
     * @param  class-string<ExportSource>  $source
     * @param  Builder<*>  $query  Filtered and access-limited like the list; ordering is added by the package.
     * @param  list<ExportColumn>  $columns
     */
    public function __construct(
        public string $source,
        public ExportFormatEnum $format,
        public Builder $query,
        public array $columns,
    ) {}
}
