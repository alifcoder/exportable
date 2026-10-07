<?php

declare(strict_types=1);

namespace Alif\Export\DTO\Export;

final readonly class ExportDownloadDTO
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $fileName,
        public string $mimeType,
    ) {}
}
