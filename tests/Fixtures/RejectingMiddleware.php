<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Fixtures;

use Alif\Export\Exceptions\ExportException;
use Alif\Export\Jobs\RunExport;
use Closure;

final class RejectingMiddleware
{
    public function handle(RunExport $job, Closure $next): mixed
    {
        throw ExportException::forbidden();
    }
}
