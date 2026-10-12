<?php

declare(strict_types=1);

namespace Alif\Export\Contracts;

use Alif\Export\Concerns\HandlesExport;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the queued job needs to read the rows of a list it was handed as SQL: the model to hydrate, the resource
 * that renders a row and the relations that resource touches. Implemented by the filter that serves the list
 * (see {@see HandlesExport}); the job resolves it by class name, so it must not depend on
 * the request or on a signed-in user.
 */
interface ExportSource
{
    /** @return array{model: class-string<Model>, resource: class-string<JsonResource>, with: array<int|string, string|Closure>} */
    public static function exportSource(): array;
}
