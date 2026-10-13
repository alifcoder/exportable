<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Fixtures;

use Alif\QueryFilter\Field;

/** Names the columns of the lines of a document. */
class LineFilter
{
    /** @param array<string, mixed> $parameters */
    public function __construct(array $parameters = []) {}

    /** @return array<int|string, mixed> */
    protected function fields(): array
    {
        return ['quantity' => Field::make('quantity'), 'product.name' => Field::make('product.name'), 'serial', 'focus_product_id' => Field::make('focus_product_id')];
    }
}
