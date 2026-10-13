<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Alif\Export\Enums\ExportFormatEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * The list's query as the export sees it: the whole filtered set on one page, in a stable order, plus the checks that
 * need only a count (nothing to export, too many rows). Used on the request, before the job exists.
 */
final class ExportQuery
{
    /**
     * The list's query without the page the list showed (limit, offset) and without eager loads, ordered by the
     * filter's own sort and then by the key so that reading it in chunks never repeats or skips a row.
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    public function wholeSet(Builder $query): Builder
    {
        $whole = clone $query;
        $whole->setEagerLoads([]);
        $whole->getQuery()->limit = null;
        $whole->getQuery()->offset = null;

        return $whole->orderBy($whole->getModel()->getQualifiedKeyName());
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
     * @throws ValidationException
     */
    public function assertNotEmpty(Builder $query, ?int $count): void
    {
        $empty = $count !== null ? $count === 0 : ! (clone $query)->reorder()->exists();

        if ($empty) {
            throw ValidationException::withMessages(['columns' => [__('export::messages.no_data')]]);
        }
    }

    /**
     * Stops an export that has more documents than the format can hold.
     *
     * @param  Builder<*>  $query
     * @param  int|null  $count  The count when the caller already has it; otherwise one row past the cap is looked for.
     *
     * @throws ValidationException
     */
    public function assertWithinCap(ExportFormatEnum $format, Builder $query, ?int $count): void
    {
        $cap = $format->maxRows();
        $exceeds = $count !== null ? $count > $cap : (clone $query)->reorder()->offset($cap)->limit(1)->exists();

        if ($exceeds) {
            throw ValidationException::withMessages([
                'format' => [__('export::messages.too_many_rows', ['format' => $format->value, 'max' => $cap])],
            ]);
        }
    }
}
