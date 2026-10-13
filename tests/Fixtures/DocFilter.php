<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Fixtures;

use Alif\Export\Concerns\HandlesExport;
use Alif\Export\Contracts\ExportSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DocFilter implements ExportSource
{
    use HandlesExport;

    protected static string $model = Doc::class;

    protected static string $resource = DocResource::class;

    /** @param array<string, mixed> $parameters */
    public function __construct(private readonly array $parameters = []) {}

    public function apply(Builder $builder): void {}

    /** @return array<int|string, mixed> */
    public function getWith(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    protected function params(): array
    {
        return $this->parameters;
    }

    /** @return Builder<Model> */
    protected function query(): Builder
    {
        return Doc::query();
    }

    /** @return array<int|string, mixed> */
    protected function fields(): array
    {
        return ['number' => 1, 'partner.name' => 1, 'date'];
    }

    /** @return array<string, class-string|array<int|string, mixed>> */
    protected function exportLists(): array
    {
        return ['lines' => [LineFilter::class, 'serials' => SerialFilter::class]];
    }
}
