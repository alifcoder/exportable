<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Feature;

use Alif\Export\ExportRegistry;
use Alif\Export\Models\DataExport;
use Alif\Export\Tests\Fixtures\Order;
use Alif\Export\Tests\Fixtures\OrderExportable;
use Alif\Export\Tests\Fixtures\OrderFilter;
use Alif\Export\Tests\Fixtures\OrderLine;
use Alif\Export\Tests\Fixtures\PlainOrderExportable;
use Alif\Export\Tests\Fixtures\TranslatedOrderExportable;
use Alif\Export\Tests\Fixtures\User;
use Alif\Export\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/** Black-box promises of section 6 of the export-sdk plan not covered by ExportFlowTest. */
final class ExportContractTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $registry = app(ExportRegistry::class);
        $registry->register('orders', OrderExportable::class);
        $registry->register('plain', PlainOrderExportable::class);
        $registry->register('translated', TranslatedOrderExportable::class);
        Gate::define('data-export', fn ($user, string $key): bool => true);

        $this->user = User::create(['name' => 'u']);
    }

    protected function tearDown(): void
    {
        OrderFilter::$scopeByAuthUser = false;

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function payload(array $file = [], array $extra = []): array
    {
        return [
            'exportable' => 'orders',
            'file' => $file + ['format' => 'csv', 'columns' => ['number', 'total']],
        ] + $extra;
    }

    private function seedTwo(): void
    {
        $a = Order::create(['number' => 'A-1', 'total' => '10.5']);
        Order::create(['number' => 'B-2', 'total' => '5']);
        foreach (['s1', 's2', 's3'] as $sku) {
            OrderLine::create(['order_id' => $a->id, 'sku' => $sku, 'qty' => 2]);
        }
    }

    private function submit(array $payload)
    {
        return $this->actingAs($this->user)->postJson('/exports', $payload);
    }

    private function completed(array $payload): DataExport
    {
        $response = $this->submit($payload);
        $response->assertStatus(202);
        $export = DataExport::findOrFail($response->json('data.id'));
        $this->assertSame('completed', $export->status, (string) $export->error_code);

        return $export;
    }

    /** @return list<list<string>> */
    private function csvRows(DataExport $export): array
    {
        $csv = substr(Storage::disk($export->disk)->get($export->path), 3);
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $csv);
        rewind($handle);
        $rows = [];
        while (($r = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rows[] = $r;
        }

        return $rows;
    }

    /** @param array<string, mixed> $attrs */
    private function makeExport(array $attrs): DataExport
    {
        return DataExport::query()->create($attrs + [
            'owner_id' => (string) $this->user->getKey(),
            'exportable' => 'orders',
            'format' => 'csv',
            'status' => DataExport::STATUS_PENDING,
            'options' => [],
            'locale' => 'en',
        ])->refresh();
    }

    // ---- validation -------------------------------------------------------

    /** "An unknown exportable returns 422 on exportable." */
    public function test_unknown_exportable_is_422_on_exportable(): void
    {
        $payload = $this->payload();
        $payload['exportable'] = 'does.not.exist';

        $this->submit($payload)->assertStatus(422)->assertJsonValidationErrors(['exportable']);
        $this->assertSame(0, DataExport::count());
    }

    /** "Duplicate columns return 422." */
    public function test_duplicate_columns_are_422(): void
    {
        $response = $this->submit($this->payload(['columns' => ['number', 'number']]));

        $response->assertStatus(422);
        $this->assertNotEmpty(array_filter(array_keys($response->json('errors')), fn ($k) => str_starts_with($k, 'file.columns')));
        $this->assertSame(0, DataExport::count());
    }

    /** "An empty columns returns 422." */
    public function test_empty_columns_are_422(): void
    {
        $this->submit($this->payload(['columns' => []]))->assertStatus(422)->assertJsonValidationErrors(['file.columns']);
        $this->assertSame(0, DataExport::count());
    }

    /** "child_columns with include_children=false ... returns 422 on file.child_columns." */
    public function test_child_columns_with_include_children_false_is_422(): void
    {
        $this->submit($this->payload(['include_children' => false, 'child_columns' => ['sku']]))
            ->assertStatus(422)->assertJsonValidationErrors(['file.child_columns']);
    }

    /** "... or on an exportable without a child relation, returns 422 on file.child_columns." */
    public function test_child_columns_on_exportable_without_child_relation_is_422(): void
    {
        $payload = $this->payload(['include_children' => true, 'child_columns' => ['sku']]);
        $payload['exportable'] = 'plain';
        $this->submit($payload)->assertStatus(422)->assertJsonValidationErrors(['file.child_columns']);

        $payload = $this->payload(['child_columns' => ['sku']]);
        $payload['exportable'] = 'plain';
        $this->submit($payload)->assertStatus(422)->assertJsonValidationErrors(['file.child_columns']);

        $this->assertSame(0, DataExport::count());
    }

    /** include_children=true needs child_columns. */
    public function test_include_children_without_child_columns_is_422(): void
    {
        $this->submit($this->payload(['include_children' => true]))
            ->assertStatus(422)->assertJsonValidationErrors(['file.child_columns']);
    }

    public function test_unknown_child_column_is_422(): void
    {
        $this->submit($this->payload(['include_children' => true, 'child_columns' => ['nope']]))
            ->assertStatus(422)->assertJsonValidationErrors(['file.child_columns.0']);
    }

    /** "data.all, data.limit and data.page are dropped silently: the export contains every matching row." */
    public function test_data_all_limit_page_are_dropped_and_every_row_is_exported(): void
    {
        $this->seedTwo();
        Order::create(['number' => 'C-3', 'total' => '1']);

        $export = $this->completed($this->payload([], ['data' => ['all' => true, 'limit' => 1, 'page' => 2, 'per_page' => 1, 'paginate' => 1]]));

        $this->assertSame(3, $export->rows_count);
        $this->assertCount(4, $this->csvRows($export));
        $this->assertArrayNotHasKey('all', $export->options['parameters'] ?? []);
        $this->assertArrayNotHasKey('limit', $export->options['parameters'] ?? []);
        $this->assertArrayNotHasKey('page', $export->options['parameters'] ?? []);
    }

    /** "An invalid filter operand returns 422 before any data_exports row is written." */
    public function test_invalid_filter_operand_is_422_and_writes_no_row(): void
    {
        $this->seedTwo();

        $this->submit($this->payload([], ['data' => ['filter' => ['total' => ['not_an_operator' => 'x']]]]))
            ->assertStatus(422);

        $this->assertSame(0, DataExport::count());
    }

    // ---- definition endpoint ---------------------------------------------

    public function test_definition_returns_key_title_formats_columns_and_child_columns(): void
    {
        config(['export.max_rows.csv' => 111, 'export.max_rows.xlsx' => 22, 'export.max_rows.pdf' => 3]);

        $response = $this->actingAs($this->user)->getJson('/exports/definition?exportable=orders');

        $response->assertOk()
            ->assertJsonPath('data.key', 'orders')
            ->assertJsonPath('data.title', 'Orders')
            ->assertJsonPath('data.formats', ['csv' => 111, 'xlsx' => 22, 'pdf' => 3])
            ->assertJsonPath('data.columns.0', ['key' => 'number', 'label' => 'Number'])
            ->assertJsonPath('data.columns.1', ['key' => 'total', 'label' => 'Total'])
            ->assertJsonPath('data.child_columns', [['key' => 'sku', 'label' => 'SKU'], ['key' => 'qty', 'label' => 'Qty']]);
        $this->assertSame(['number', 'total', 'lines_count', 'broken', 'undeclared'], array_column($response->json('data.columns'), 'key'));
    }

    public function test_definition_for_exportable_without_children_has_empty_child_columns(): void
    {
        $this->actingAs($this->user)->getJson('/exports/definition?exportable=plain')
            ->assertOk()->assertJsonPath('data.child_columns', []);
    }

    /** "A user the Gate denies gets 403 on ... the definition endpoint." */
    public function test_definition_is_403_for_denied_user(): void
    {
        Gate::define('data-export', fn (): bool => false);

        $this->actingAs($this->user)->getJson('/exports/definition?exportable=orders')->assertForbidden();
    }

    public function test_definition_for_unknown_key_is_404(): void
    {
        $this->actingAs($this->user)->getJson('/exports/definition?exportable=nope')->assertNotFound();
    }

    // ---- file layout ------------------------------------------------------

    /** "Column order in the file equals file.columns followed by file.child_columns, in the requested order, with translated labels." */
    public function test_column_order_follows_request_and_labels_are_translated(): void
    {
        $this->seedTwo();
        app('translator')->addLines([
            'messages.col_number' => 'Raqam', 'messages.col_total' => 'Jami',
            'messages.col_sku' => 'Artikul', 'messages.col_qty' => 'Soni',
        ], 'uz');
        app()->setLocale('uz');

        $export = $this->completed([
            'exportable' => 'translated',
            'file' => ['format' => 'csv', 'columns' => ['total', 'number'], 'include_children' => true, 'child_columns' => ['qty', 'sku']],
        ]);

        $rows = $this->csvRows($export);
        $this->assertSame(['Jami', 'Raqam', 'Soni', 'Artikul'], $rows[0]);
        $row = collect($rows)->first(fn ($r) => $r[1] === 'A-1');
        $this->assertSame('2', $row[2]);
        $this->assertSame('s1', $row[3]);
    }

    // ---- N+1 ---------------------------------------------------------------

    /** "Building the export runs a constant number of queries regardless of document count." */
    public function test_query_count_is_constant_regardless_of_document_count(): void
    {
        $measure = function (): int {
            $count = 0;
            DB::listen(function () use (&$count): void {
                $count++;
            });
            $this->completed($this->payload(['columns' => ['number', 'lines_count'], 'include_children' => true, 'child_columns' => ['sku', 'qty']]));

            return $count;
        };

        $this->seedTwo();
        $few = $measure();

        for ($i = 0; $i < 28; $i++) {
            $o = Order::create(['number' => 'N-'.$i, 'total' => '1']);
            OrderLine::create(['order_id' => $o->id, 'sku' => 'x'.$i, 'qty' => 1]);
            OrderLine::create(['order_id' => $o->id, 'sku' => 'y'.$i, 'qty' => 1]);
        }
        $many = $measure();

        $this->assertLessThan(60, $few);
        $this->assertSame($few, $many, "3 vs 30 documents ran {$few} vs {$many} queries");
    }

    // ---- caps -------------------------------------------------------------

    /** "More documents than the format cap returns 422 on file.format." (pdf) */
    public function test_pdf_cap_is_enforced_at_submit(): void
    {
        $this->seedTwo();
        config(['export.max_rows.pdf' => 1]);

        $this->submit($this->payload(['format' => 'pdf']))
            ->assertStatus(422)->assertJsonValidationErrors(['file.format']);
        $this->assertSame(0, DataExport::count());
    }

    // ---- hostile content end to end -----------------------------------------

    public function test_pdf_export_with_hostile_cell_completes_and_is_a_pdf(): void
    {
        Order::create(['number' => '<img src="http://127.0.0.1:1/evil"><script>x</script>', 'total' => '1']);

        $export = $this->completed($this->payload(['format' => 'pdf']));

        $this->assertStringStartsWith('%PDF', Storage::disk($export->disk)->get($export->path));
    }

    public function test_csv_end_to_end_prefixes_text_but_not_numeric_negative(): void
    {
        Order::create(['number' => '+1', 'total' => '-5']);

        $rows = $this->csvRows($this->completed($this->payload()));

        // The driver decides the decimal text ('-5' on sqlite, '-5.000000' on Postgres); the sign must survive unprefixed.
        $this->assertSame("'+1", $rows[1][0]);
        $this->assertEqualsWithDelta(-5.0, (float) $rows[1][1], 0.0000001);
        $this->assertStringStartsWith('-5', $rows[1][1]);
    }

    // ---- download -----------------------------------------------------------

    public function test_download_is_409_while_pending_or_processing(): void
    {
        foreach ([DataExport::STATUS_PENDING, DataExport::STATUS_PROCESSING] as $status) {
            $export = $this->makeExport(['status' => $status]);

            $this->actingAs($this->user)->get("/exports/{$export->id}/download")->assertStatus(409);
        }
    }

    public function test_download_is_410_when_expired(): void
    {
        $this->seedTwo();
        $export = $this->completed($this->payload());
        $export->update(['expires_at' => now()->subMinute()]);

        $this->actingAs($this->user)->get("/exports/{$export->id}/download")->assertStatus(410);
    }

    public function test_download_is_410_when_file_is_missing(): void
    {
        $this->seedTwo();
        $export = $this->completed($this->payload());
        Storage::disk($export->disk)->delete($export->path);

        $this->actingAs($this->user)->get("/exports/{$export->id}/download")->assertStatus(410);
    }

    public function test_completed_download_is_200_attachment_with_slug_date_filename(): void
    {
        $this->seedTwo();
        foreach (['csv' => 'text/csv', 'xlsx' => 'spreadsheetml', 'pdf' => 'application/pdf'] as $format => $mime) {
            $export = $this->completed($this->payload(['format' => $format]));

            $response = $this->actingAs($this->user)->get("/exports/{$export->id}/download");
            $response->assertOk();
            $disposition = (string) $response->headers->get('content-disposition');

            $this->assertStringStartsWith('attachment', $disposition);
            $this->assertMatchesRegularExpression('/filename=orders_\d{4}-\d{2}-\d{2}_\d{6}\.'.$format.'/', $disposition);
            $this->assertStringContainsString($mime, (string) $response->headers->get('content-type'));
        }
    }

    public function test_download_by_non_owner_is_404(): void
    {
        $this->seedTwo();
        $export = $this->completed($this->payload());
        $other = User::create(['name' => 'o']);

        $this->actingAs($other)->get("/exports/{$export->id}/download")->assertNotFound();
    }

    public function test_resource_never_exposes_disk_or_path(): void
    {
        $this->seedTwo();
        $export = $this->completed($this->payload());

        $json = $this->actingAs($this->user)->getJson("/exports/{$export->id}")->assertOk();

        $this->assertArrayNotHasKey('disk', $json->json('data'));
        $this->assertArrayNotHasKey('path', $json->json('data'));
        $this->assertStringNotContainsString((string) $export->path, $json->getContent());
        $this->assertNotNull($json->json('data.download_url'));
    }

    // ---- prune --------------------------------------------------------------

    /** "model:prune removes expired rows and their files." */
    public function test_prune_removes_expired_completed_rows_and_their_files_but_keeps_live_ones(): void
    {
        $this->seedTwo();
        $expired = $this->completed($this->payload());
        $live = $this->completed($this->payload());
        $expired->update(['expires_at' => now()->subMinute()]);
        Storage::disk($expired->disk)->assertExists($expired->path);

        $this->artisan('model:prune', ['--model' => [DataExport::class]])->assertSuccessful();

        $this->assertNull(DataExport::find($expired->id));
        Storage::disk($expired->disk)->assertMissing($expired->path);
        $this->assertNotNull(DataExport::find($live->id));
        Storage::disk($live->disk)->assertExists($live->path);
    }
}
