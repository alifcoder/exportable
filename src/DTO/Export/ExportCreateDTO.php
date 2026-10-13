<?php

declare(strict_types=1);

namespace Alif\Export\DTO\Export;

use Alif\Export\Contracts\ExportSource;
use Alif\Export\Enums\ExportFormatEnum;

/** What the job writes, fully resolved on the request: the source class, format, typed columns and the prepared query. */
final readonly class ExportCreateDTO
{
    /**
     * @param  class-string<ExportSource>  $source
     * @param  list<ExportColumn>  $columns
     */
    public function __construct(
        public string $source,
        public ExportFormatEnum $format,
        public array $columns,
        public PreparedQuery $query,
    ) {}

    /**
     * Builds the DTO back from the array saved in the job payload.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            source: $data['source'],
            format: ExportFormatEnum::from($data['format']),
            columns: array_map(ExportColumn::fromArray(...), $data['columns']),
            query: PreparedQuery::fromArray($data['query']),
        );
    }

    /**
     * Turns the DTO into a plain array for the job payload.
     *
     * @return array<string, mixed>
     */
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
