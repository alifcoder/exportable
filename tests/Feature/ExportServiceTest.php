<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Feature;

use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\DTO\Export\ExportListDTO;
use Alif\Export\Entities\DataExport;
use Alif\Export\Enums\ExportFormat;
use Alif\Export\Enums\ExportStatus;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportRegistry;
use Alif\Export\Jobs\RunExport;
use Alif\Export\Services\Interfaces\ExportServiceInterface;
use Alif\Export\Tests\Concerns\MakesExports;
use Alif\Export\Tests\Fixtures\OrderExportable;
use Alif\Export\Tests\Fixtures\User;
use Alif\Export\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

final class ExportServiceTest extends TestCase
{
    use MakesExports;

    private User $user;

    private User $other;

    private ExportServiceInterface $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        app(ExportRegistry::class)->register('orders', OrderExportable::class);
        Gate::define('data-export', fn ($user, string $key): bool => true);
        $this->user = User::create(['name' => 'u']);
        $this->other = User::create(['name' => 'o']);
        $this->service = app(ExportServiceInterface::class);
    }

    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (ExportException $e) {
            return $e->errorCode;
        }
        $this->fail('Expected ExportException');
    }

    private function completed(array $attrs = []): DataExport
    {
        Storage::disk('local')->put('exports/f.csv', 'data');

        return $this->makeExport($this->user, $attrs + [
            'status' => ExportStatus::COMPLETED,
            'disk' => 'local',
            'path' => 'exports/f.csv',
            'file_name' => 'orders.csv',
            'expires_at' => now()->addHour(),
        ]);
    }

    // ---- definition --------------------------------------------------------

    public function test_definition_returns_the_exportable_for_a_permitted_known_key(): void
    {
        $this->assertInstanceOf(OrderExportable::class, $this->service->definition($this->user, 'orders'));
    }

    public function test_definition_is_forbidden_for_unknown_and_denied_keys_alike(): void
    {
        $this->assertSame('forbidden', $this->code(fn () => $this->service->definition($this->user, 'nope')));
        $this->assertSame('forbidden', $this->code(fn () => $this->service->definition($this->user, '')));

        Gate::define('data-export', fn (): bool => false);
        $this->assertSame('forbidden', $this->code(fn () => $this->service->definition($this->user, 'orders')));
    }

    // ---- all ---------------------------------------------------------------

    public function test_all_returns_only_the_owners_rows_newest_first_with_id_as_tiebreaker(): void
    {
        $a = $this->makeExport($this->user, ['created_at' => now()->subMinutes(5)]);
        $b = $this->makeExport($this->user, ['created_at' => now()]);
        $this->makeExport($this->other, ['created_at' => now()]);

        $ids = collect($this->service->all(new ExportListDTO((string) $this->user->getKey()))->items())->pluck('id')->all();

        $this->assertSame([$b->id, $a->id], $ids);
    }

    public function test_all_filters_by_status_and_by_exportable_independently_and_combined(): void
    {
        app(ExportRegistry::class)->register('other', OrderExportable::class);
        $ownerId = (string) $this->user->getKey();
        $f1 = $this->makeExport($this->user, ['status' => ExportStatus::FAILED]);
        $p1 = $this->makeExport($this->user, ['status' => ExportStatus::PENDING]);
        $f2 = $this->makeExport($this->user, ['status' => ExportStatus::FAILED, 'options' => ['exportable' => 'other']]);

        $ids = fn (ExportListDTO $dto): array => collect($this->service->all($dto)->items())->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$f1->id, $f2->id])->sort()->values()->all(), $ids(new ExportListDTO($ownerId, ExportStatus::FAILED)));
        $this->assertSame([$f2->id], $ids(new ExportListDTO($ownerId, null, 'other')));
        $this->assertSame([$f1->id], $ids(new ExportListDTO($ownerId, ExportStatus::FAILED, 'orders')));
        $this->assertSame([], $ids(new ExportListDTO($ownerId, ExportStatus::COMPLETED)));
        $this->assertCount(3, $this->service->all(new ExportListDTO($ownerId))->items());
        unset($p1);
    }

    public function test_all_paginates_with_simple_pagination(): void
    {
        foreach (range(1, 5) as $_) {
            $this->makeExport($this->user);
        }

        $page = $this->service->all(new ExportListDTO((string) $this->user->getKey(), perPage: 2));

        $this->assertCount(2, $page->items());
        $this->assertTrue($page->hasMorePages());
    }

    // ---- find --------------------------------------------------------------

    public function test_find_returns_the_owners_export(): void
    {
        $export = $this->makeExport($this->user);

        $this->assertSame($export->id, $this->service->find($this->user, $export->id)->id);
    }

    public function test_find_hides_foreign_and_missing_exports_behind_the_same_not_found(): void
    {
        $foreign = $this->makeExport($this->other);

        $this->assertSame('not_found', $this->code(fn () => $this->service->find($this->user, $foreign->id)));
        $this->assertSame('not_found', $this->code(fn () => $this->service->find($this->user, '00000000-0000-4000-8000-000000000000')));
    }

    // ---- create / retry / delete ---------------------------------------------

    public function test_create_delegates_to_start_and_dispatches(): void
    {
        Queue::fake();
        $dto = new ExportCreateDTO('orders', ExportFormat::CSV, ['number'], false, [], null, []);

        $export = $this->service->create($this->user, $dto);

        $this->assertSame(ExportStatus::PENDING, $export->status);
        Queue::assertPushed(RunExport::class);
    }

    public function test_retry_and_delete_of_a_foreign_export_are_not_found_and_change_nothing(): void
    {
        Queue::fake();
        $foreign = $this->makeExport($this->other, ['status' => ExportStatus::FAILED]);

        $this->assertSame('not_found', $this->code(fn () => $this->service->retry($this->user, $foreign->id)));
        $this->assertSame('not_found', $this->code(fn () => $this->service->delete($this->user, $foreign->id)));
        $this->assertNotNull(DataExport::find($foreign->id));
        Queue::assertNothingPushed();
    }

    public function test_delete_removes_an_owned_export_and_its_file(): void
    {
        $export = $this->completed();

        $this->service->delete($this->user, $export->id);

        $this->assertNull(DataExport::find($export->id));
        Storage::disk('local')->assertMissing('exports/f.csv');
    }

    // ---- download ----------------------------------------------------------

    public function test_download_returns_location_file_name_and_the_format_mime_type(): void
    {
        $csv = $this->service->download($this->user, $this->completed()->id);

        $this->assertSame(['local', 'exports/f.csv', 'orders.csv', 'text/csv'], [$csv->disk, $csv->path, $csv->fileName, $csv->mimeType]);

        $xlsx = $this->service->download($this->user, $this->completed(['format' => 'xlsx', 'options' => ['format' => 'xlsx']])->id);
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $xlsx->mimeType);
    }

    public function test_download_of_a_foreign_export_is_not_found(): void
    {
        $foreign = $this->makeExport($this->other, ['status' => ExportStatus::COMPLETED, 'disk' => 'local', 'path' => 'exports/f.csv']);

        $this->assertSame('not_found', $this->code(fn () => $this->service->download($this->user, $foreign->id)));
    }

    /** @return array<string, array{ExportStatus}> */
    public static function notCompleted(): array
    {
        return ['pending' => [ExportStatus::PENDING], 'processing' => [ExportStatus::PROCESSING], 'failed' => [ExportStatus::FAILED]];
    }

    #[DataProvider('notCompleted')]
    public function test_download_before_completion_is_not_ready(ExportStatus $status): void
    {
        $export = $this->makeExport($this->user, ['status' => $status]);

        $this->assertSame('not_ready', $this->code(fn () => $this->service->download($this->user, $export->id)));
    }

    public function test_download_permission_is_checked_before_readiness(): void
    {
        $pending = $this->makeExport($this->user);
        Gate::define('data-export', fn (): bool => false);

        $this->assertSame('forbidden', $this->code(fn () => $this->service->download($this->user, $pending->id)));
    }

    public function test_download_of_an_expired_export_is_file_unavailable(): void
    {
        $export = $this->completed(['expires_at' => now()->subSecond()]);

        $this->assertSame('file_unavailable', $this->code(fn () => $this->service->download($this->user, $export->id)));
    }

    public function test_download_with_the_file_gone_from_storage_is_file_unavailable(): void
    {
        $export = $this->completed();
        Storage::disk('local')->delete('exports/f.csv');

        $this->assertSame('file_unavailable', $this->code(fn () => $this->service->download($this->user, $export->id)));
    }

    public function test_download_of_a_completed_row_with_no_recorded_file_is_file_unavailable(): void
    {
        $export = $this->makeExport($this->user, ['status' => ExportStatus::COMPLETED, 'expires_at' => now()->addHour()]);

        $this->assertSame('file_unavailable', $this->code(fn () => $this->service->download($this->user, $export->id)));
    }

    public function test_download_without_an_expiry_is_still_served(): void
    {
        $export = $this->completed(['expires_at' => null]);

        $this->assertSame('orders.csv', $this->service->download($this->user, $export->id)->fileName);
    }
}
