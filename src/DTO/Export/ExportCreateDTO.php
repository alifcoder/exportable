<?php

declare(strict_types=1);

namespace Alif\Export\DTO\Export;

use Alif\Export\Contracts\ExportSource;
use Alif\Export\Enums\ExportFormat;

/** What the job writes, fully resolved on the request: the source class, format, typed columns and the prepared query. */
final readonly class ExportCreateDTO
{
    /**
     * @param  class-string<ExportSource>  $source
     * @param  list<ExportColumn>  $columns
     */
    public function __construct(
        public string $source,
        public ExportFormat $format,
        public array $columns,
        public PreparedQuery $query,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            source: $data['source'],
            format: ExportFormat::from($data['format']),
            columns: array_map(ExportColumn::fromArray(...), $data['columns']),
            query: PreparedQuery::fromArray($data['query']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'format' => $this->format->value,
            'columns' => array_map(fn (ExportColumn $c): array => $c->toArray(), $this->columns),
            'query' => $this->query->toArray(),
        ];
    }
}
