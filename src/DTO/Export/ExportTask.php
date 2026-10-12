<?php

declare(strict_types=1);

namespace Alif\Export\DTO\Export;

/** One queued export: its id, the locale and what to write. Scalar-only so it travels in a job payload. */
final readonly class ExportTask
{
    public function __construct(
        public string $id,
        public string $locale,
        public ExportCreateDTO $request,
        public ?int $totalRows,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            locale: $data['locale'],
            request: ExportCreateDTO::fromArray($data['request']),
            totalRows: $data['total_rows'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'locale' => $this->locale,
            'request' => $this->request->toArray(),
            'total_rows' => $this->totalRows,
        ];
    }
}
