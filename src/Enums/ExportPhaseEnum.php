<?php

declare(strict_types=1);

namespace Alif\Export\Enums;

/** The step an export is in, in the order it passes them. */
enum ExportPhaseEnum: string
{
    case QUEUED = 'queued';
    case QUERYING = 'querying';
    case WRITING = 'writing';
    case UPLOADING = 'uploading';
}
