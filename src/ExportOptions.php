<?php

declare(strict_types=1);

namespace Alif\Export;

use Alif\Export\Enums\ExportFormat;

final readonly class ExportOptions
{
    /**
     * @param  list<string>  $columns
     * @param  list<string>  $childColumns
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public string $exportable,
        public ExportFormat $format,
        public array $columns,
        public bool $includeChildren,
        public array $childColumns,
        public ?string $title,
        public array $parameters,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            exportable: $data['exportable'],
            format: ExportFormat::from($data['format']),
            columns: array_values($data['columns']),
            includeChildren: (bool) $data['include_children'],
            childColumns: array_values($data['child_columns'] ?? []),
            title: $data['title'] ?? null,
            parameters: $data['parameters'] ?? [],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'exportable' => $this->exportable,
            'format' => $this->format->value,
            'columns' => $this->columns,
            'include_children' => $this->includeChildren,
            'child_columns' => $this->childColumns,
            'title' => $this->title,
            'parameters' => $this->parameters,
        ];
    }
}
