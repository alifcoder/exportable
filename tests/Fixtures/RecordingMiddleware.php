<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Fixtures;

use Alif\Export\Jobs\RunExport;
use Closure;

final class RecordingMiddleware
{
    /** @var list<string> */
    public static array $calls = [];

    public function handle(RunExport $job, Closure $next): mixed
    {
        self::$calls[] = 'before:'.$job->task['id'];
        $result = $next($job);
        self::$calls[] = 'after:'.$job->task['id'];

        return $result;
    }
}
