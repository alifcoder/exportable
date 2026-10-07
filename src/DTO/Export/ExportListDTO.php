<?php

declare(strict_types=1);

namespace Alif\Export\DTO\Export;

final readonly class ExportListDTO
{
    public function __construct(
        public string $ownerId,
        public ?string $status = null,
        public ?string $exportable = null,
        public int $perPage = 20,
    ) {}
}
