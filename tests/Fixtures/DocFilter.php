<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Fixtures;

use Alif\Export\Concerns\HandlesExport;
use Alif\Export\Helpers\ExportValidator;
use Illuminate\Database\Eloquent\Builder;

/** A stand-in for a host's model filter: the members `HandlesExport` reads, and a window onto its private checks. */
final class DocFilter
{
    use HandlesExport;

    public static string $resource = DocResource::class;

    /** @return array<string, mixed> */
    public function params(): array
    {
        return [];
    }

    /** @return Builder<Doc> */
    public function query(): Builder
    {
        return Doc::query();
    }

    /** @param  Builder<Doc>  $query */
    public function apply(Builder $query): void {}

    /**
     * @param  Builder<Doc>  $query
     * @return list<string>
     */
    public function eagerLoads(Builder $query): array
    {
        return ExportValidator::validateEagerLoads($query);
    }
}
