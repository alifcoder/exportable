<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Fixtures;

use Alif\QueryFilter\Field;

/** Names the columns of the serials of a line. */
class SerialFilter
{
    /** @param array<string, mixed> $parameters */
    public function __construct(array $parameters = []) {}

    /** @return array<int|string, mixed> */
    protected function fields(): array
    {
        return ['imei' => Field::make('imei')];
    }
}
