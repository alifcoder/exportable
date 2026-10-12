<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Feature;

use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\DTO\Export\PreparedQuery;
use Alif\Export\Enums\ColumnType;
use Alif\Export\Enums\ExportFormat;
use Alif\Export\Events\ExportProgressed;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportConfig;
use Alif\Export\Helpers\ExportProgress;
use Alif\Export\Helpers\ExportWriter;
use Alif\Export\Tests\Fixtures\OrderFilter;
use Alif\Export\Tests\Fixtures\User;
use Alif\Export\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Behaviour the host configures: no package defaults, progress events, date formats. */
final class ExportConfigBehaviourTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'u']);
    }

    private function makeTask(User $owner, ?int $total = null): ExportTask
    {
        $dto = new ExportCreateDTO(OrderFilter::class, ExportFormat::CSV, [], null, [], new PreparedQuery(null, 'select 1', [], 0, null));

        return new ExportTask('00000000-0000-4000-8000-000000000001', (string) $owner->getKey(), 'en', $dto, $total);
    }

    /** @return list<list<int>> */
    private function rows(int $count): array
    {
        return array_map(fn (int $i): array => [$i], range(1, $count));
    }

    public function test_a_missing_key_is_a_configuration_error_naming_the_key(): void
    {
        config(['export.events' => null]);

        $this->expectException(ExportException::class);
        $this->expectExceptionMessage('export.events.finished');

        ExportConfig::bool('events.finished');
    }

    public function test_a_nullable_key_must_still_be_present(): void
    {
        config(['export.queue' => ['name' => 'exports']]);

        $this->expectException(ExportException::class);

        ExportConfig::nullable('queue.connection');
    }

    public function test_progress_is_sent_every_configured_step_and_ends_with_100(): void
    {
        Event::fake([ExportProgressed::class]);
        config(['export.progress' => ['enabled' => true, 'min_rows' => 10, 'step_percent' => 20]]);
        $task = $this->makeTask($this->user, 100);

        $out = iterator_to_array(app(ExportProgress::class)->track($task, $this->rows(100)), false);

        $this->assertCount(100, $out, 'rows pass through untouched');
        $percents = Event::dispatched(ExportProgressed::class)->map(fn (array $args): int => $args[0]->percent)->all();
        $this->assertSame([20, 40, 60, 80, 100], $percents);
        Event::assertDispatched(ExportProgressed::class, fn (ExportProgressed $e): bool => $e->exportId === $task->id
            && $e->ownerId === (string) $this->user->getKey() && $e->totalRows === 100);
    }

    public function test_progress_is_off_when_disabled_below_the_threshold_or_without_a_total(): void
    {
        Event::fake([ExportProgressed::class]);

        config(['export.progress' => ['enabled' => false, 'min_rows' => 1, 'step_percent' => 1]]);
        iterator_to_array(app(ExportProgress::class)->track($this->makeTask($this->user, 100), $this->rows(100)));

        config(['export.progress' => ['enabled' => true, 'min_rows' => 101, 'step_percent' => 1]]);
        iterator_to_array(app(ExportProgress::class)->track($this->makeTask($this->user, 100), $this->rows(100)));

        config(['export.progress' => ['enabled' => true, 'min_rows' => 1, 'step_percent' => 1]]);
        iterator_to_array(app(ExportProgress::class)->track($this->makeTask($this->user, null), $this->rows(10)));

        Event::assertNotDispatched(ExportProgressed::class);
    }

    public function test_a_failing_progress_listener_never_fails_the_export(): void
    {
        config(['export.progress' => ['enabled' => true, 'min_rows' => 1, 'step_percent' => 10]]);
        Event::listen(ExportProgressed::class, fn () => throw new RuntimeException('socket down'));

        $out = iterator_to_array(app(ExportProgress::class)->track($this->makeTask($this->user, 50), $this->rows(50)), false);

        $this->assertCount(50, $out);
    }

    public function test_dates_use_the_configured_formats_and_a_date_column_drops_the_time(): void
    {
        config(['export.style.date_format' => 'd.m.Y', 'export.style.datetime_format' => 'd.m.Y H:i']);
        $moment = Carbon::parse('2026-03-04 05:06:07');
        $path = Storage::disk('local')->path('dates.csv');
        Storage::fake('local');

        app(ExportWriter::class)->store(ExportFormat::CSV, 't', ['Moment', 'Day'], [ColumnType::DateTime, ColumnType::Date], [[$moment, $moment]], $path);

        $this->assertStringContainsString("\"04.03.2026 05:06\",04.03.2026\n", (string) file_get_contents($path));
    }
}
