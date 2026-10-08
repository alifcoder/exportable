<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Feature;

use Alif\Export\Enums\ExportStatus;
use Alif\Export\Events\ExportFinished;
use Alif\Export\Helpers\ExportRegistry;
use Alif\Export\Tests\Concerns\MakesExports;
use Alif\Export\Tests\Fixtures\OrderExportable;
use Alif\Export\Tests\Fixtures\User;
use Alif\Export\Tests\TestCase;
use Alif\Export\Transformers\Export\ExportResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

final class ExportResourceAndEventTest extends TestCase
{
    use MakesExports;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        app(ExportRegistry::class)->register('orders', OrderExportable::class);
        Gate::define('data-export', fn (): bool => true);
        $this->user = User::create(['name' => 'u']);
    }

    /** @return array<string, mixed> */
    private function resource(array $attrs = []): array
    {
        return (new ExportResource($this->makeExport($this->user, $attrs)))->toArray(Request::create('/'));
    }

    public function test_pending_export_shape(): void
    {
        $data = $this->resource();

        $this->assertSame(
            ['id', 'exportable', 'format', 'status', 'rows_count', 'error_code', 'created_at', 'finished_at', 'expires_at', 'download_url'],
            array_keys($data),
        );
        $this->assertSame('pending', $data['status']);
        $this->assertSame('orders', $data['exportable']);
        $this->assertSame('csv', $data['format']);
        $this->assertNull($data['rows_count']);
        $this->assertNull($data['error_code']);
        $this->assertNull($data['finished_at']);
        $this->assertNull($data['expires_at']);
        $this->assertNull($data['download_url']);
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $data['created_at']));
    }

    public function test_completed_export_exposes_a_download_url_and_iso_dates(): void
    {
        $data = $this->resource([
            'status' => ExportStatus::COMPLETED, 'disk' => 'local', 'path' => 'p.csv', 'rows_count' => 3,
            'finished_at' => now(), 'expires_at' => now()->addHour(),
        ]);

        $this->assertSame(3, $data['rows_count']);
        $this->assertStringEndsWith('/exports/'.$data['id'].'/download', $data['download_url']);
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $data['finished_at']));
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $data['expires_at']));
    }

    public function test_expired_failed_and_unfilled_exports_have_no_download_url(): void
    {
        $this->assertNull($this->resource(['status' => ExportStatus::COMPLETED, 'disk' => 'local', 'path' => 'p', 'expires_at' => now()->subMinute()])['download_url']);
        $this->assertNull($this->resource(['status' => ExportStatus::FAILED, 'disk' => 'local', 'path' => 'p'])['download_url']);
        $this->assertNull($this->resource(['status' => ExportStatus::COMPLETED])['download_url']);
    }

    public function test_failed_export_exposes_the_error_code_only(): void
    {
        $data = $this->resource(['status' => ExportStatus::FAILED, 'error_code' => 'forbidden', 'disk' => 'local', 'path' => 'secret/p.csv']);

        $this->assertSame('forbidden', $data['error_code']);
        $this->assertStringNotContainsString('secret', json_encode($data));
        $this->assertArrayNotHasKey('options', $data);
        $this->assertArrayNotHasKey('owner_id', $data);
        $this->assertArrayNotHasKey('path', $data);
    }

    public function test_finished_event_carries_the_export_and_is_dispatchable(): void
    {
        Event::fake([ExportFinished::class]);
        $export = $this->makeExport($this->user);

        ExportFinished::dispatch($export);

        Event::assertDispatched(ExportFinished::class, fn (ExportFinished $e): bool => $e->export->is($export));
    }

    public function test_download_response_has_attachment_disposition_and_the_format_content_type(): void
    {
        Storage::disk('local')->put('exports/x.xlsx', 'bin');
        $export = $this->makeExport($this->user, [
            'status' => ExportStatus::COMPLETED, 'format' => 'xlsx', 'options' => ['format' => 'xlsx'],
            'disk' => 'local', 'path' => 'exports/x.xlsx', 'file_name' => 'orders.xlsx', 'expires_at' => now()->addHour(),
        ]);

        $response = $this->actingAs($this->user)->get("/exports/{$export->id}/download")->assertOk();

        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('orders.xlsx', $response->headers->get('Content-Disposition'));
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertSame('bin', $response->streamedContent());
    }
}
