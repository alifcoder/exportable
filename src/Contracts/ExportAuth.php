<?php

declare(strict_types=1);

namespace Alif\Export\Contracts;

use Alif\Export\Exceptions\ExportException;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

interface ExportAuth
{
    public function allows(Authenticatable $user, string $exportable): bool;

    /**
     * Run $callback (receiving the owner) signed in as the owner, then restore the previous user and guard.
     *
     * @param  Closure(Authenticatable): mixed  $callback
     *
     * @throws ExportException
     */
    public function actingAs(string|int $ownerId, Closure $callback): mixed;
}
