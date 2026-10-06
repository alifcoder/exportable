<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Fixtures;

use Alif\QueryFilter\Abstracts\BaseEBFilter;
use Alif\QueryFilter\Field;
use Illuminate\Database\Eloquent\Builder;

final class OrderFilter extends BaseEBFilter
{
    protected static array $with = ['lines'];

    /** When true, scope rows to the authenticated user like a host checkUserBy() would. */
    public static bool $scopeByAuthUser = false;

    protected function fields(): array
    {
        return [
            'number' => Field::make('number')->filterable()->sortable(),
            'total' => Field::make('total')->filterable()->sortable(),
        ];
    }

    protected function before(Builder $builder): void
    {
        if (self::$scopeByAuthUser) {
            $builder->where('orders.owner', (string) auth()->user()?->getAuthIdentifier());
        }
    }
}
