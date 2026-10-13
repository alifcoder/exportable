<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Fixtures;

/** Names the columns of the lines of a document. */
class LineFilter
{
    /** @param array<string, mixed> $parameters */
    public function __construct(array $parameters = []) {}

    /** @return array<int|string, mixed> */
    protected function fields(): array
    {
        return ['quantity' => 1, 'product.name' => 1, 'serial'];
    }
}
