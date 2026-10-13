<?php

declare(strict_types=1);

namespace Alif\Export\DTO\Export;

use Alif\Export\Enums\ColumnTypeEnum;

/**
 * One requested column: the key in the resource output, the heading the client chose and the type its cells are written as.
 * A column with `$groups` reads its key from each item of a list in the resource output: `['products']` is the items of
 * `products`, `['products', 'serials']` the items of `serials` inside each product. The export writes those items as
 * sub rows below their parent.
 */
final readonly class ExportColumn
{
    public function __construct(
        public string $key,
        public string $label,
        public ?ColumnTypeEnum $type = null,
        /** @var list<string> */
        public array $groups = [],
    ) {}

    /** Returns a copy of this column with another type. */
    public function withType(?ColumnTypeEnum $type): self
    {
        return new self($this->key, $this->label, $type, $this->groups);
    }

    /**
     * Builds the column back from the array saved in the job payload.
     *
     * @param  array{key: string, label: string, type?: string|null, groups?: list<string>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['key'], $data['label'], isset($data['type']) ? ColumnTypeEnum::from($data['type']) : null, $data['groups'] ?? []);
    }

    /**
     * Turns the column into a plain array for the job payload.
     *
     * @return array{key: string, label: string, type: string|null, groups: list<string>}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'type' => $this->type?->value, 'groups' => $this->groups];
    }
}
