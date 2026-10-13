<?php

declare(strict_types=1);

namespace Alif\Export\Contracts;

use Alif\Export\Concerns\HandlesExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/** What the export reads from the filter that serves a list (the filter uses {@see HandlesExport}). */
interface ExportableFilter
{
    /**
     * The list's ready query: the filter applied, no limit or offset, and a stable ORDER BY. Called once per export;
     * the package reads it as given and never changes it.
     *
     * @return Builder<Model>
     */
    public function exportQuery(): Builder;

    /**
     * The resource that renders one row, as the list shows it.
     *
     * @return class-string<JsonResource>
     */
    public function exportResource(): string;
}
