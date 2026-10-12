<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Fixtures;

use Alif\Export\Concerns\HandlesExport;
use Alif\Export\Contracts\ExportSource;
use Alif\QueryFilter\Abstracts\BaseEBFilter;
use Alif\QueryFilter\Field;
use Illuminate\Database\Eloquent\Builder;

/** A host list filter using the package trait, like an ERP ModelFilter. */
final class OrderFilter extends BaseEBFilter implements ExportSource
{
    use HandlesExport;

    protected static string $model = Order::class;

    protected static string $resource = OrderResource::class;

    protected static array $with = ['lines'];

    /** When true, scope rows to the authenticated user like a host before() would. */
    public static bool $scopeByAuthUser = false;

    /** @var array<string, mixed> */
    private array $given;

    /** @param array<string, mixed> $parameters */
    public function __construct(array $parameters = [])
    {
        $this->given = $parameters;

        parent::__construct($parameters);
    }

    protected function givenParameters(): array
    {
        return $this->given;
    }

    protected function fields(): array
    {
        return [
            'number' => Field::make('number')->filterable()->sortable(),
            'total' => Field::make('total')->filterable()->sortable(),
        ];
    }

    protected function before(Builder $builder): void
    {
        if (self::$scopeByAuthUser) {
            $builder->where('orders.owner', (string) auth()->user()?->getAuthIdentifier());
        }
    }

    /** The page the list would show (`per_page`, `page`). */
    protected function exportWindow(Builder $query): void
    {
        $perPage = (int) ($this->given['per_page'] ?? 0);

        if ($perPage > 0) {
            $query->limit($perPage)->offset((max(1, (int) ($this->given['page'] ?? 1)) - 1) * $perPage);
        }
    }
}
