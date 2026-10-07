<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Fixtures;

use Alif\Export\Contracts\Exportable;
use Alif\Export\Helpers\Column;
use Alif\QueryFilter\Interfaces\EBFilterInterface;
use Illuminate\Database\Eloquent\Builder;

final class OrderExportable implements Exportable
{
    public function title(): string
    {
        return 'Orders';
    }

    public function columns(): array
    {
        return [
            'number' => Column::make('Number'),
            'total' => Column::make('Total')->numeric(),
            'lines_count' => Column::make('Line count', fn (Order $o): int => $o->lines->count())->relations('lines'),
            'broken' => Column::make('Broken', fn (Order $o) => $o->lines->count() ? $o->owner : $o->number),
            'undeclared' => Column::make('Undeclared', fn (Order $o): int => $o->lines->count()),
        ];
    }

    public function childRelation(): ?string
    {
        return 'lines';
    }

    public function childColumns(): array
    {
        return [
            'sku' => Column::make('SKU'),
            'qty' => Column::make('Qty')->numeric(),
        ];
    }

    public function query(): Builder
    {
        return Order::query();
    }

    public function filter(array $parameters): EBFilterInterface
    {
        return new OrderFilter($parameters);
    }
}
