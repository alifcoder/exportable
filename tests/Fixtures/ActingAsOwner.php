<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Fixtures;

use Alif\Export\Exceptions\ExportException;
use Alif\Export\Jobs\RunExport;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/** What a host puts in `export.queue.middleware`: run the job as its owner and re-check the permission. */
final class ActingAsOwner
{
    public function handle(RunExport $job, Closure $next): mixed
    {
        $guard = Auth::guard('web');
        $owner = Auth::createUserProvider((string) config('auth.guards.web.provider'))?->retrieveById($job->task['owner_id']);

        if (! $owner instanceof Authenticatable) {
            throw ExportException::ownerMissing();
        }

        if (! Gate::forUser($owner)->allows('data-export', [$job->task['request']['exportable']])) {
            throw ExportException::forbidden();
        }

        $previousGuard = Auth::getDefaultDriver();
        $previousUser = $guard->hasUser() ? $guard->user() : null;
        Auth::shouldUse('web');
        $guard->setUser($owner);

        try {
            return $next($job);
        } finally {
            if ($previousUser instanceof Authenticatable) {
                $guard->setUser($previousUser);
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }
            Auth::shouldUse($previousGuard);
        }
    }
}
