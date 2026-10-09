<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Unit;

use Alif\Export\Tests\TestCaseWithoutDatabase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;

final class ProviderRegistersNothingByDefaultTest extends TestCaseWithoutDatabase
{
    public function test_no_routes_are_registered(): void
    {
        foreach (['export.index', 'export.store', 'export.show', 'export.download', 'export.definition'] as $name) {
            $this->assertNull(Route::getRoutes()->getByName($name), $name);
        }
    }

    public function test_no_prune_schedule_is_registered(): void
    {
        $commands = array_map(fn ($event): string => (string) $event->command, app(Schedule::class)->events());

        $this->assertSame([], array_values(array_filter($commands, fn (string $c): bool => str_contains($c, 'model:prune'))));
    }

    public function test_the_package_merges_no_config_defaults(): void
    {
        $app = $this->app;
        $app['config']->set('export', []);

        $this->assertNull(config('export.ttl_hours'));
    }
}
