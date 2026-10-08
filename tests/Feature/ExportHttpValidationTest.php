<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Feature;

use Alif\Export\Entities\DataExport;
use Alif\Export\Enums\ExportStatus;
use Alif\Export\Helpers\ExportRegistry;
use Alif\Export\Tests\Concerns\MakesExports;
use Alif\Export\Tests\Fixtures\Order;
use Alif\Export\Tests\Fixtures\OrderExportable;
use Alif\Export\Tests\Fixtures\PlainOrderExportable;
use Alif\Export\Tests\Fixtures\User;
use Alif\Export\Tests\TestCase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

final class ExportHttpValidationTest extends TestCase
{
    use MakesExports;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
        app(ExportRegistry::class)->register('orders', OrderExportable::class);
        app(ExportRegistry::class)->register('plain', PlainOrderExportable::class);
        Gate::define('data-export', fn ($user, string $key): bool => $key !== 'plain');
        $this->user = User::create(['name' => 'u']);
    }

    /** @return array<string, mixed> */
    private function valid(): array
    {
        return ['exportable' => 'orders', 'file' => ['format' => 'csv', 'columns' => ['number']]];
    }

    /** @param array<string, mixed> $override dot paths => value; null value with key present unsets */
    private function payload(array $override = [], array $remove = []): array
    {
        $payload = $this->valid();
        foreach ($override as $path => $value) {
            data_set($payload, $path, $value);
        }
        foreach ($remove as $path) {
            Arr::forget($payload, $path);
        }

        return $payload;
    }

    private function store(array $payload)
    {
        return $this->actingAs($this->user)->postJson('/exports', $payload);
    }

    // ---- authentication ----------------------------------------------------------

    /** @return array<string, array{string, string}> */
    public static function protectedRoutes(): array
    {
        $id = '00000000-0000-4000-8000-000000000000';

        return [
            'index' => ['getJson', '/exports'],
            'store' => ['postJson', '/exports'],
            'definition' => ['getJson', '/exports/definition?exportable=orders'],
            'show' => ['getJson', "/exports/{$id}"],
            'download' => ['getJson', "/exports/{$id}/download"],
            'retry' => ['postJson', "/exports/{$id}/retry"],
            'destroy' => ['deleteJson', "/exports/{$id}"],
        ];
    }

    #[DataProvider('protectedRoutes')]
    public function test_every_route_requires_authentication(string $verb, string $uri): void
    {
        $this->{$verb}($uri)->assertUnauthorized();
        $this->assertSame(0, DataExport::count());
    }

    // ---- store: field rules -------------------------------------------------------

    /** @return array<string, array{array<string, mixed>, list<string>, string}> */
    public static function invalidPayloads(): array
    {
        return [
            'exportable missing' => [[], ['exportable'], 'exportable'],
            'exportable null' => [['exportable' => null], [], 'exportable'],
            'exportable array' => [['exportable' => ['orders']], [], 'exportable'],
            'exportable unknown' => [['exportable' => 'nope'], [], 'exportable'],
            'exportable too long' => [['exportable' => str_repeat('a', 101)], [], 'exportable'],
            'file missing' => [[], ['file'], 'file'],
            'file not array' => [['file' => 'csv'], [], 'file'],
            'format missing' => [[], ['file.format'], 'file.format'],
            'format unknown' => [['file.format' => 'pdf'], [], 'file.format'],
            'format uppercase' => [['file.format' => 'CSV'], [], 'file.format'],
            'format array' => [['file.format' => ['csv']], [], 'file.format'],
            'columns missing' => [[], ['file.columns'], 'file.columns'],
            'columns empty' => [['file.columns' => []], [], 'file.columns'],
            'columns string' => [['file.columns' => 'number'], [], 'file.columns'],
            'column unknown' => [['file.columns' => ['number', 'ghost']], [], 'file.columns.1'],
            'column not string' => [['file.columns' => [5]], [], 'file.columns.0'],
            'column duplicated' => [['file.columns' => ['number', 'number']], [], 'file.columns.0'],
            'column empty string' => [['file.columns' => ['']], [], 'file.columns.0'],
            'include_children not boolean' => [['file.include_children' => 'maybe'], [], 'file.include_children'],
            'include_children without child columns' => [['file.include_children' => true], [], 'file.child_columns'],
            'include_children with empty child columns' => [['file.include_children' => true, 'file.child_columns' => []], [], 'file.child_columns'],
            'child column unknown' => [['file.include_children' => true, 'file.child_columns' => ['ghost']], [], 'file.child_columns.0'],
            'child column duplicated' => [['file.include_children' => true, 'file.child_columns' => ['sku', 'sku']], [], 'file.child_columns.0'],
            'child columns without include_children' => [['file.child_columns' => ['sku']], [], 'file.child_columns'],
            'title too long' => [['file.title' => str_repeat('t', 151)], [], 'file.title'],
            'title not string' => [['file.title' => ['x']], [], 'file.title'],
            'data not array' => [['data' => 'all'], [], 'data'],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     * @param  list<string>  $remove
     */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_store_payloads_are_422_on_the_offending_field_and_create_nothing(array $override, array $remove, string $errorKey): void
    {
        $this->store($this->payload($override, $remove))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$errorKey]);

        $this->assertSame(0, DataExport::count());
        Queue::assertNothingPushed();
    }

    public function test_valid_payload_is_202_and_creates_one_pending_row(): void
    {
        $this->store($this->valid())->assertStatus(202)->assertJsonPath('data.status', 'pending');

        $this->assertSame(1, DataExport::count());
    }

    public function test_title_at_the_limit_and_null_title_are_accepted(): void
    {
        $this->store($this->payload(['file.title' => str_repeat('t', 150)]))->assertStatus(202);
        $this->store($this->payload(['file.title' => null]))->assertStatus(202);
        $this->store($this->payload(['exportable' => str_repeat('a', 100)]))->assertUnprocessable();
    }

    public function test_include_children_accepts_boolean_like_values(): void
    {
        config(['export.max_active_per_user' => 10]);

        foreach ([true, false, 1, 0, '1', '0'] as $value) {
            $payload = $this->payload(['file.include_children' => $value]);
            if (in_array($value, [true, 1, '1'], true)) {
                data_set($payload, 'file.child_columns', ['sku']);
            }

            $this->store($payload)->assertStatus(202);
        }
    }

    public function test_data_null_or_missing_is_accepted(): void
    {
        config(['export.max_active_per_user' => 10]);

        $this->store($this->payload(['data' => null]))->assertStatus(202);
        $this->store($this->valid())->assertStatus(202);
    }

    public function test_every_validation_error_is_reported_at_once(): void
    {
        $this->store(['exportable' => 'nope', 'file' => ['format' => 'pdf', 'columns' => []]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['exportable', 'file.format', 'file.columns']);
    }

    // ---- authorization on store --------------------------------------------------------

    public function test_forbidden_exportable_is_403_even_when_the_rest_of_the_payload_is_invalid(): void
    {
        $this->store(['exportable' => 'plain', 'file' => ['format' => 'pdf']])->assertForbidden();
        $this->assertSame(0, DataExport::count());
    }

    public function test_a_gate_receiving_the_requested_key_decides_per_exportable(): void
    {
        $this->store(['exportable' => 'plain'] + $this->valid())->assertForbidden();
        $this->store($this->valid())->assertStatus(202);
    }

    // ---- data parameters --------------------------------------------------------------

    public function test_only_whitelisted_data_keys_reach_the_stored_parameters(): void
    {
        $response = $this->store($this->valid() + ['data' => [
            'sort' => 'number', 'limit' => 1, 'page' => 3, 'all' => true, 'evil' => 'drop table',
        ]])->assertStatus(202);

        $this->assertSame(['sort' => 'number'], DataExport::findOrFail($response->json('data.id'))->options['parameters']);
    }

    public function test_data_whitelist_is_configurable(): void
    {
        config(['export.data_parameters' => ['limit']]);

        $response = $this->store($this->valid() + ['data' => ['sort' => 'number', 'limit' => 1]])->assertStatus(202);

        $this->assertSame(['limit' => 1], DataExport::findOrFail($response->json('data.id'))->options['parameters']);
    }

    public function test_stored_options_reflect_the_request_exactly(): void
    {
        $response = $this->store($this->payload([
            'file.format' => 'xlsx',
            'file.columns' => ['total', 'number'],
            'file.include_children' => true,
            'file.child_columns' => ['qty'],
            'file.title' => 'Mine',
        ]))->assertStatus(202);

        $this->assertSame([
            'exportable' => 'orders',
            'format' => 'xlsx',
            'columns' => ['total', 'number'],
            'include_children' => true,
            'child_columns' => ['qty'],
            'title' => 'Mine',
            'parameters' => [],
        ], DataExport::findOrFail($response->json('data.id'))->options);
    }

    // ---- store: errors surfaced as HTTP -----------------------------------------------

    public function test_row_cap_is_422_on_file_format(): void
    {
        config(['export.max_rows.csv' => 1]);
        Order::create(['number' => 'a', 'total' => 1]);
        Order::create(['number' => 'b', 'total' => 1]);

        $this->store($this->valid())->assertUnprocessable()->assertJsonValidationErrors(['file.format']);
    }

    public function test_quota_is_429_with_a_json_message(): void
    {
        config(['export.max_active_per_user' => 1]);
        $this->makeExport($this->user);

        $this->store($this->valid())->assertStatus(429)->assertJsonPath('message', 'Too many active exports.');
    }

    // ---- list --------------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function invalidListQueries(): array
    {
        return [
            'unknown status' => ['status=bogus'],
            'status array' => ['status[]=failed'],
            'per_page zero' => ['per_page=0'],
            'per_page negative' => ['per_page=-1'],
            'per_page over max' => ['per_page=101'],
            'per_page text' => ['per_page=abc'],
            'per_page float' => ['per_page=1.5'],
            'exportable too long' => ['exportable='.'a'.str_repeat('a', 100)],
        ];
    }

    #[DataProvider('invalidListQueries')]
    public function test_invalid_list_queries_are_422(string $query): void
    {
        $this->actingAs($this->user)->getJson('/exports?'.$query)->assertUnprocessable();
    }

    public function test_list_boundaries_per_page_1_and_100_are_accepted(): void
    {
        $this->actingAs($this->user)->getJson('/exports?per_page=1')->assertOk();
        $this->actingAs($this->user)->getJson('/exports?per_page=100')->assertOk();
    }

    public function test_list_defaults_to_twenty_per_page_and_follows_pages(): void
    {
        foreach (range(1, 25) as $i) {
            $this->makeExport($this->user, ['status' => ExportStatus::FAILED, 'created_at' => now()->subMinutes($i)]);
        }

        $first = $this->actingAs($this->user)->getJson('/exports')->assertOk()->assertJsonCount(20, 'data');
        $second = $this->actingAs($this->user)->getJson('/exports?page=2')->assertOk()->assertJsonCount(5, 'data');

        $this->assertNotNull($first->json('links.next'));
        $this->assertNull($second->json('links.next'));
        $this->assertEmpty(array_intersect(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id')));
    }

    public function test_list_filters_by_each_status_and_by_exportable(): void
    {
        app(ExportRegistry::class)->register('other', OrderExportable::class);
        foreach (ExportStatus::cases() as $status) {
            $this->makeExport($this->user, ['status' => $status]);
        }
        $this->makeExport($this->user, ['options' => ['exportable' => 'other']]);

        foreach (ExportStatus::cases() as $status) {
            $json = $this->actingAs($this->user)->getJson('/exports?status='.$status->value)->assertOk();
            $statuses = array_unique(array_column($json->json('data'), 'status'));
            $this->assertSame([$status->value], array_values($statuses));
        }

        $this->actingAs($this->user)->getJson('/exports?exportable=other')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.exportable', 'other');
        $this->actingAs($this->user)->getJson('/exports?exportable=missing')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->user)->getJson('/exports?status=pending&exportable=other')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->user)->getJson('/exports?status=failed&exportable=other')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_list_ignores_an_owner_query_parameter(): void
    {
        $other = User::create(['name' => 'o']);
        $this->makeExport($other);

        $this->actingAs($this->user)->getJson('/exports?owner_id='.$other->getKey())->assertOk()->assertJsonCount(0, 'data');
    }

    // ---- owner routes -------------------------------------------------------------------

    public function test_non_uuid_ids_do_not_match_any_owner_route(): void
    {
        foreach (['getJson' => '/exports/abc', 'deleteJson' => '/exports/abc', 'postJson' => '/exports/abc/retry', 'getJson ' => '/exports/abc/download'] as $verb => $uri) {
            $this->actingAs($this->user)->{trim($verb)}($uri)->assertNotFound();
        }
    }

    public function test_unknown_uuid_is_404_with_a_json_message_on_every_owner_route(): void
    {
        $id = '00000000-0000-4000-8000-000000000000';

        $this->actingAs($this->user)->getJson("/exports/{$id}")->assertNotFound()->assertJsonPath('message', 'Export not found.');
        $this->actingAs($this->user)->getJson("/exports/{$id}/download")->assertNotFound();
        $this->actingAs($this->user)->postJson("/exports/{$id}/retry")->assertNotFound();
        $this->actingAs($this->user)->deleteJson("/exports/{$id}")->assertNotFound();
    }

    public function test_foreign_export_is_404_not_403_on_show_and_download(): void
    {
        $other = User::create(['name' => 'o']);
        $foreign = $this->makeExport($other, ['status' => ExportStatus::COMPLETED, 'disk' => 'local', 'path' => 'x.csv', 'expires_at' => now()->addHour()]);

        $this->actingAs($this->user)->getJson("/exports/{$foreign->id}")->assertNotFound();
        $this->actingAs($this->user)->getJson("/exports/{$foreign->id}/download")->assertNotFound();
    }

    public function test_retry_over_http_is_202_with_a_new_pending_export(): void
    {
        $failed = $this->makeExport($this->user, ['status' => ExportStatus::FAILED, 'error_code' => 'export_failed']);

        $response = $this->actingAs($this->user)->postJson("/exports/{$failed->id}/retry")->assertStatus(202);

        $this->assertNotSame($failed->id, $response->json('data.id'));
        $response->assertJsonPath('data.status', 'pending');
    }

    public function test_retry_of_a_non_failed_export_is_409(): void
    {
        $pending = $this->makeExport($this->user);

        $this->actingAs($this->user)->postJson("/exports/{$pending->id}/retry")->assertStatus(409)->assertJsonPath('message', 'Only failed exports can be retried.');
    }

    public function test_delete_over_http_is_204_with_an_empty_body(): void
    {
        $export = $this->makeExport($this->user);

        $this->actingAs($this->user)->deleteJson("/exports/{$export->id}")->assertNoContent();
        $this->actingAs($this->user)->deleteJson("/exports/{$export->id}")->assertNotFound();
    }

    // ---- definition ----------------------------------------------------------------------

    public function test_definition_without_the_query_parameter_or_with_an_array_is_403(): void
    {
        $this->actingAs($this->user)->getJson('/exports/definition')->assertForbidden();
    }

    public function test_definition_response_shape(): void
    {
        $this->actingAs($this->user)->getJson('/exports/definition?exportable=orders')
            ->assertOk()
            ->assertJsonStructure(['data' => ['key', 'title', 'formats' => ['csv', 'xlsx'], 'columns' => [['key', 'label']], 'child_columns' => [['key', 'label']]]])
            ->assertJsonPath('data.key', 'orders')
            ->assertJsonPath('data.formats.csv', 500000);
    }
}
