<?php

declare(strict_types=1);

namespace Alif\Export\Transformers\Export;

use Alif\Export\Contracts\Exportable;
use Alif\Export\Enums\ExportFormat;
use Alif\Export\Helpers\Column;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property Exportable $resource */
final class ExportDefinitionResource extends JsonResource
{
    public function __construct(Exportable $resource, private readonly string $key)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'title' => (string) __($this->resource->title()),
            'formats' => collect(ExportFormat::cases())
                ->mapWithKeys(fn (ExportFormat $format): array => [$format->value => $format->maxRows()])
                ->all(),
            'columns' => $this->labels($this->resource->columns()),
            'child_columns' => $this->labels($this->resource->childColumns()),
        ];
    }

    /**
     * @param  array<string, Column>  $columns
     * @return list<array{key: string, label: string}>
     */
    private function labels(array $columns): array
    {
        return collect($columns)
            ->map(fn (Column $column, string $key): array => ['key' => $key, 'label' => (string) __($column->label())])
            ->values()
            ->all();
    }
}
