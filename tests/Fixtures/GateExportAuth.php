<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Fixtures;

use Alif\Export\Contracts\ExportAuth;
use Alif\Export\Exceptions\ExportException;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

final class GateExportAuth implements ExportAuth
{
    public function allows(Authenticatable $user, string $exportable): bool
    {
        return Gate::forUser($user)->allows('data-export', [$exportable]);
    }

    public function actingAs(string|int $ownerId, Closure $callback): mixed
    {
        $guardName = 'web';
        $provider = Auth::createUserProvider((string) config("auth.guards.{$guardName}.provider"));
        $owner = $provider?->retrieveById($ownerId);

        if (! $owner instanceof Authenticatable) {
            throw ExportException::ownerMissing();
        }

        $guard = Auth::guard($guardName);
        $previousGuard = Auth::getDefaultDriver();
        $previousUser = $guard->hasUser() ? $guard->user() : null;

        Auth::shouldUse($guardName);
        $guard->setUser($owner);

        try {
            return $callback($owner);
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
