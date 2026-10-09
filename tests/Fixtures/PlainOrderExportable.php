<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Fixtures;

use Alif\Export\Contracts\Exportable;
use Alif\Export\Helpers\Column;
use Alif\QueryFilter\Interfaces\EBFilterInterface;
use Illuminate\Database\Eloquent\Builder;

/** A document without a child relation. */
final class PlainOrderExportable implements Exportable
{
    public function title(): string
    {
        return 'Plain';
    }

    public function columns(): array
    {
        return ['number' => Column::make('Number'), 'total' => Column::make('Total')->numeric()];
    }

    public function childRelation(): ?string
    {
        return null;
    }

    public function childColumns(): array
    {
        return [];
    }

    public function query(array $parameters): Builder
    {
        return Order::query();
    }

    public function filter(array $parameters): EBFilterInterface
    {
        return new OrderFilter($parameters);
    }
}
