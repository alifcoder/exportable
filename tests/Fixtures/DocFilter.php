<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Fixtures;

use Alif\Export\Concerns\HandlesExport;
use Alif\Export\Contracts\ExportableFilter;
use Alif\QueryFilter\Enums\FieldType;
use Alif\QueryFilter\Field;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DocFilter implements ExportableFilter
{
    use HandlesExport;

    /** @param array<string, mixed> $parameters */
    public function __construct(private readonly array $parameters = []) {}

    /** @return array<string, mixed> */
    protected function params(): array
    {
        return $this->parameters;
    }

    /** @return Builder<Model> */
    public function exportQuery(): Builder
    {
        return Doc::query()->orderBy('id');
    }

    /** @return class-string<DocResource> */
    public function exportResource(): string
    {
        return DocResource::class;
    }

    /** @return array<int|string, mixed> */
    protected function fields(): array
    {
        return ['number' => Field::make('number')->type(FieldType::Integer), 'partner.name' => Field::make('partner.name'), 'date', 'partner_id' => Field::make('partner_id'), 'partner.region_id' => Field::make('partner.region_id'), 'id' => Field::make('id')];
    }

    /** @return array<string, class-string|array<int|string, mixed>> */
    protected function exportLists(): array
    {
        return ['lines' => [LineFilter::class, 'serials' => SerialFilter::class]];
    }
}
