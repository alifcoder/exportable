<?php

declare(strict_types=1);

namespace Alif\Export\DTO\Export;

use Alif\Export\Exceptions\ExportException;
use Illuminate\Database\Eloquent\Builder;

/**
 * A query that is ready to run: everything the host's list applies (filter, access limits, ordering, selects) is
 * compiled into SQL on the request, so the job reads it without a user, a filter or a request. `limit`/`offset`
 * are the list's own window, kept apart so the job can page through it.
 */
final readonly class PreparedQuery
{
    /** Drivers whose `limit n offset m` tail the job appends to {@see $sql}. */
    private const array DRIVERS = ['mysql', 'mariadb', 'pgsql', 'sqlite'];

    /** @param list<mixed> $bindings */
    public function __construct(
        public ?string $connection,
        public string $sql,
        public array $bindings,
        public int $offset,
        public ?int $limit,
    ) {}

    /** @param Builder<*> $builder The applied, ordered list query. */
    public static function from(Builder $builder): self
    {
        $connection = $builder->getModel()->getConnection();

        if (! in_array($connection->getDriverName(), self::DRIVERS, true)) {
            throw ExportException::invalidRegistration(sprintf('Export cannot page the "%s" driver.', $connection->getDriverName()));
        }

        $base = $builder->toBase();
        $offset = (int) ($base->offset ?? 0);
        $limit = $base->limit === null ? null : (int) $base->limit;
        $base->limit = null;
        $base->offset = null;

        return new self($builder->getModel()->getConnectionName(), $base->toSql(), $base->getBindings(), $offset, $limit);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self($data['connection'], $data['sql'], $data['bindings'], $data['offset'], $data['limit']);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['connection' => $this->connection, 'sql' => $this->sql, 'bindings' => $this->bindings, 'offset' => $this->offset, 'limit' => $this->limit];
    }
}
