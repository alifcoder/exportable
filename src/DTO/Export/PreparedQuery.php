<?php

declare(strict_types=1);

namespace Alif\Export\DTO\Export;

use Alif\Export\Exceptions\ExportException;
use Illuminate\Database\Eloquent\Builder;

/** The list query saved as SQL text and bindings, so the job can run it later without the request. */
final readonly class PreparedQuery
{
    /** The only driver supported: the job appends a `limit n offset m` tail to {@see $sql}. */
    private const string DRIVER = 'pgsql';

    /** @param list<mixed> $bindings */
    public function __construct(
        public ?string $connection,
        public string $sql,
        public array $bindings,
        public int $offset,
        public ?int $limit,
    ) {}

    /**
     * Turns an Eloquent query into SQL and bindings. The limit and offset are taken out and kept apart, so the job can read page by page.
     *
     * @param Builder<*> $builder The applied, ordered list query.
     */
    public static function from(Builder $builder): self
    {
        $connection = $builder->getModel()->getConnection();

        if ($connection->getDriverName() !== self::DRIVER) {
            throw ExportException::invalidRegistration(sprintf('Export cannot page the "%s" driver.', $connection->getDriverName()));
        }

        $base = $builder->toBase();
        $offset = (int) ($base->offset ?? 0);
        $limit = $base->limit === null ? null : (int) $base->limit;
        $base->limit = null;
        $base->offset = null;

        return new self($builder->getModel()->getConnectionName(), $base->toSql(), $base->getBindings(), $offset, $limit);
    }

    /**
     * Builds the query back from the array saved in the job payload.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['connection'], $data['sql'], $data['bindings'], $data['offset'], $data['limit']);
    }

    /**
     * Turns the query into a plain array for the job payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['connection' => $this->connection, 'sql' => $this->sql, 'bindings' => $this->bindings, 'offset' => $this->offset, 'limit' => $this->limit];
    }
}
