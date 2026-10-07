<?php

declare(strict_types=1);

namespace Alif\Export\DTO\Export;

use Alif\Export\Enums\ExportStatus;

final readonly class ExportListDTO
{
    public function __construct(
        public string $ownerId,
        public ?ExportStatus $status = null,
        public ?string $exportable = null,
        public int $perPage = 20,
    ) {}
}
