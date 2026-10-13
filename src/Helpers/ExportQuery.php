<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Alif\Export\Enums\ExportFormatEnum;
use Alif\Export\Exceptions\ExportException;
use Illuminate\Database\Eloquent\Builder;

/**
 * The checks on the host's query before the job exists: it can be read in chunks, it selects something, it fits the
 * format. Every check works on a clone; the received query is never changed.
 */
final class ExportQuery
{
    /**
     * The job appends `limit n offset m` to the compiled SQL, so the query must not have its own page, and the chunks
     * only follow each other without repeats or gaps when the query has an ORDER BY. The host prepares both.
     *
     * @param  Builder<*>  $query
     *
     * @throws ExportException
     */
    public function assertPageable(Builder $query): void
    {
        $base = $query->getQuery();

        if ($base->limit !== null || $base->offset !== null) {
            throw ExportException::unpageableQuery('it has a limit or offset');
        }

        if ($base->orders === null || $base->orders === []) {
            throw ExportException::unpageableQuery('it has no ORDER BY');
        }
    }

    /**
     * How many documents the query selects.
     *
     * @param  Builder<*>  $query
     */
    public function count(Builder $query): int
    {
        $keys = (clone $query)->reorder()->select($query->getModel()->getQualifiedKeyName())->toBase();

        return (int) $query->getModel()->getConnection()->query()->fromSub($keys, 'counted')->count();
    }

    /**
     * An empty file is never produced.
     *
     * @param  Builder<*>  $query
     * @param  int|null  $count  The count when the caller already has it; otherwise one row is looked for.
     *
     * @throws ExportException
     */
    public function assertNotEmpty(Builder $query, ?int $count): void
    {
        $empty = $count !== null ? $count === 0 : ! (clone $query)->reorder()->exists();

        if ($empty) {
            throw ExportException::noData();
        }
    }

    /**
     * Stops an export that has more documents than the format can hold.
     *
     * @param  Builder<*>  $query
     * @param  int|null  $count  The count when the caller already has it; otherwise one row past the cap is looked for.
     *
     * @throws ExportException
     */
    public function assertWithinCap(ExportFormatEnum $format, Builder $query, ?int $count): void
    {
        $cap = $format->maxRows();
        $exceeds = $count !== null ? $count > $cap : (clone $query)->reorder()->offset($cap)->limit(1)->exists();

        if ($exceeds) {
            throw ExportException::tooManyRows($format, $cap);
        }
    }
}
