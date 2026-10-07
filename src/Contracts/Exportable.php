<?php

declare(strict_types=1);

namespace Alif\Export\Contracts;

use Alif\Export\Helpers\Column;
use Alif\QueryFilter\Interfaces\EBFilterInterface;
use Illuminate\Database\Eloquent\Builder;

interface Exportable
{
    /** Translation key or text used as the default file title. */
    public function title(): string;

    /** @return array<string, Column> Document columns keyed by public column key. */
    public function columns(): array;

    /** HasMany relation name on the root model, or null when the document has no children. */
    public function childRelation(): ?string;

    /** @return array<string, Column> Columns evaluated against the child model; empty when none. */
    public function childColumns(): array;

    /** Unfiltered base query. */
    public function query(): Builder;

    /** @param array<string, mixed> $parameters Sanitized data parameters. */
    public function filter(array $parameters): EBFilterInterface;
}
