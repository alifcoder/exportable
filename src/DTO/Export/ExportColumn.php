<?php

declare(strict_types=1);

namespace Alif\Export\DTO\Export;

use Alif\Export\Enums\ColumnType;

/** One requested column: the key in the resource output, the heading the client chose and the type its cells are written as. */
final readonly class ExportColumn
{
    public function __construct(
        public string $key,
        public string $label,
        public ?ColumnType $type = null,
    ) {}

    public function withType(?ColumnType $type): self
    {
        return new self($this->key, $this->label, $type);
    }

    /** @param array{key: string, label: string, type?: string|null} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['key'], $data['label'], isset($data['type']) ? ColumnType::from($data['type']) : null);
    }

    /** @return array{key: string, label: string, type: string|null} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'type' => $this->type?->value];
    }
}
