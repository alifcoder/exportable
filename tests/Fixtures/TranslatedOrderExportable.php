<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Fixtures;

use Alif\Export\Contracts\Exportable;
use Alif\Export\Helpers\Column;
use Alif\QueryFilter\Interfaces\EBFilterInterface;
use Illuminate\Database\Eloquent\Builder;

/** Labels are translation keys (lines are added by the tests). */
final class TranslatedOrderExportable implements Exportable
{
    public function title(): string
    {
        return 'messages.orders_title';
    }

    public function columns(): array
    {
        return [
            'number' => Column::make('messages.col_number'),
            'total' => Column::make('messages.col_total')->numeric(),
        ];
    }

    public function childRelation(): ?string
    {
        return 'lines';
    }

    public function childColumns(): array
    {
        return [
            'sku' => Column::make('messages.col_sku'),
            'qty' => Column::make('messages.col_qty')->numeric(),
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
