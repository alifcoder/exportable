<?php

declare(strict_types=1);

namespace Alif\Export\Tests;

use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportQuery;
use Alif\Export\Tests\Fixtures\Doc;
use Illuminate\Database\Eloquent\Builder;

final class EagerLoadsTest extends TestCase
{
    public function test_plain_eager_loads_are_recorded_by_name(): void
    {
        $relations = (new ExportQuery)->eagerLoads(Doc::query()->with(['lines', 'lines.approvals']));

        $this->assertSame(['lines', 'lines.approvals'], $relations);
    }

    public function test_a_constraint_declared_on_the_relation_is_plain(): void
    {
        $relations = (new ExportQuery)->eagerLoads(Doc::query()->with('activeLines'));

        $this->assertSame(['activeLines'], $relations);
    }

    public function test_a_constraint_closure_is_refused(): void
    {
        try {
            (new ExportQuery)->eagerLoads(Doc::query()->with(['lines' => fn (Builder $q) => $q->where('quantity', '>', 0)]));
            $this->fail('A constrained eager load must be refused.');
        } catch (ExportException $e) {
            $this->assertSame('unsupported_eager_load', $e->errorCode);
            $this->assertSame(500, $e->status);
            $this->assertStringContainsString('"lines"', $e->getMessage());
        }
    }

    public function test_a_column_select_is_refused(): void
    {
        $this->expectException(ExportException::class);

        (new ExportQuery)->eagerLoads(Doc::query()->with('lines:id,quantity'));
    }
}
