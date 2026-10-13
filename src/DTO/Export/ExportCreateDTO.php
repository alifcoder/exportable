<?php

declare(strict_types=1);

namespace Alif\Export\DTO\Export;

use Alif\Export\Enums\ExportFormatEnum;
use Alif\Export\Exceptions\ExportException;

/** What the job writes, fully resolved on the request: format, typed columns, the prepared query and how to read its rows. */
final readonly class ExportCreateDTO
{
    /**
     * @param  list<ExportColumn>  $columns
     */
    public function __construct(
        public ExportFormatEnum $format,
        public array $columns,
        public PreparedQuery $query,
        public RowSource $rows,
    ) {}

    /**
     * Builds the DTO back from the array saved in the job payload.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ExportException A payload queued by an older version, without `rows`.
     */
    public static function fromArray(array $data): self
    {
        if (! isset($data['rows']) || ! is_array($data['rows'])) {
            throw ExportException::invalidRegistration('The queued export has no "rows"; it was queued by an older version.');
        }

        return new self(
            format: ExportFormatEnum::from($data['format']),
            columns: array_map(ExportColumn::fromArray(...), $data['columns']),
            query: PreparedQuery::fromArray($data['query']),
            rows: RowSource::fromArray($data['rows']),
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
            'format' => $this->format->value,
            'columns' => array_map(fn (ExportColumn $c): array => $c->toArray(), $this->columns),
            'query' => $this->query->toArray(),
            'rows' => $this->rows->toArray(),
        ];
    }
}
