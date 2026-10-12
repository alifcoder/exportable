<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Feature;

use Alif\Export\Events\ExportFinished;
use Alif\Export\Jobs\RunExport;
use Alif\Export\Tests\Fixtures\DiskFileStore;
use Alif\Export\Tests\Fixtures\Order;
use Alif\Export\Tests\Fixtures\OrderFilter;
use Alif\Export\Tests\Fixtures\OrderLine;
use Alif\Export\Tests\Fixtures\User;
use Alif\Export\Tests\TestCase;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Everything the request decides before a job exists. */
final class ExportRequestValidationTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
        $this->user = User::create(['name' => 'u']);
        $order = Order::create(['number' => 'A-1', 'total' => '10.5']);
        OrderLine::create(['order_id' => $order->id, 'sku' => 's1', 'qty' => 2]);
    }

    protected function tearDown(): void
    {
        DiskFileStore::$failPut = false;

        parent::tearDown();
    }

    /** @param array<string, mixed> $parameters */
    private function export(array $parameters): mixed
    {
        return (new OrderFilter($parameters))->export($this->user);
    }

    /** @return array<string, list<string>> */
    private function errors(array $parameters): array
    {
        try {
            $this->export($parameters);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        $this->fail('The request was accepted.');
    }

    public function test_a_valid_request_is_queued_with_a_task_and_no_file_yet(): void
    {
        $task = $this->export(['format' => 'csv', 'columns' => ['number' => 'Number']]);

        Queue::assertPushed(RunExport::class, 1);
        $this->assertSame((string) $this->user->getKey(), $task->ownerId);
        $this->assertSame(1, $task->totalRows);
    }

    public function test_excel_is_an_accepted_format(): void
    {
        $task = $this->export(['format' => 'excel', 'columns' => ['number' => 'Number']]);

        $this->assertSame('excel', $task->request->format->value);
    }

    public function test_the_format_is_required_and_must_be_csv_or_excel(): void
    {
        $columns = ['number' => 'Number'];

        $this->assertArrayHasKey('format', $this->errors(['columns' => $columns]));
        $this->assertArrayHasKey('format', $this->errors(['columns' => $columns, 'format' => 'xlsx']));
        $this->assertArrayHasKey('format', $this->errors(['columns' => $columns, 'format' => 'pdf']));
    }

    public function test_columns_are_required(): void
    {
        $this->assertArrayHasKey('columns', $this->errors(['format' => 'csv']));
        $this->assertArrayHasKey('columns', $this->errors(['format' => 'csv', 'columns' => []]));
        $this->assertArrayHasKey('columns', $this->errors(['format' => 'csv', 'columns' => 'number']));
    }

    public function test_columns_must_map_a_key_to_a_heading_not_list_keys(): void
    {
        $this->assertArrayHasKey('columns', $this->errors(['format' => 'csv', 'columns' => ['number', 'total']]));
    }

    public function test_every_heading_is_a_non_empty_bounded_string(): void
    {
        foreach (['', '   ', 5, null, str_repeat('x', 151)] as $label) {
            $this->assertArrayHasKey('columns', $this->errors(['format' => 'csv', 'columns' => ['number' => $label]]), var_export($label, true));
        }
    }

    public function test_only_one_relation_with_columns_may_be_exported(): void
    {
        $errors = $this->errors(['format' => 'csv', 'columns' => ['number' => 'N', 'lines' => ['sku' => 'S'], 'other' => ['x' => 'X']]]);

        $this->assertArrayHasKey('columns', $errors);
    }

    public function test_a_relation_needs_columns_and_cannot_nest_deeper(): void
    {
        $this->assertArrayHasKey('columns', $this->errors(['format' => 'csv', 'columns' => ['number' => 'N', 'lines' => []]]));
        $this->assertArrayHasKey('columns', $this->errors(['format' => 'csv', 'columns' => ['number' => 'N', 'lines' => ['deep' => ['sku' => 'S']]]]));
        $this->assertArrayHasKey('columns', $this->errors(['format' => 'csv', 'columns' => ['number' => 'N', 'lines' => ['sku' => '']]]));
    }

    public function test_an_unknown_column_is_rejected_on_columns_naming_the_key(): void
    {
        $errors = $this->errors(['format' => 'csv', 'columns' => ['number' => 'N', 'nope' => 'Nope']]);

        $this->assertSame(['Unknown column "nope".'], $errors['columns']);
    }

    public function test_an_unknown_child_column_is_rejected_on_the_relation(): void
    {
        $errors = $this->errors(['format' => 'csv', 'columns' => ['number' => 'N', 'lines' => ['sku' => 'S', 'nope' => 'Nope']]]);

        $this->assertSame(['Unknown column "nope".'], $errors['columns.lines']);
    }

    public function test_a_list_the_resource_does_not_render_as_flat_keys_is_not_a_column(): void
    {
        $this->assertArrayHasKey('columns', $this->errors(['format' => 'csv', 'columns' => ['tags' => 'Tags']]));
        $this->assertArrayHasKey('columns', $this->errors(['format' => 'csv', 'columns' => ['lines' => 'Lines']]));
    }

    public function test_a_relation_that_is_not_a_has_many_is_rejected(): void
    {
        foreach (['ownerUser', 'nothing', 'fresh', 'delete', 'getKey', 'save', 'push', 'touch', 'newQuery', 'bad key'] as $relation) {
            $errors = $this->errors(['format' => 'csv', 'columns' => ['number' => 'N', $relation => ['x' => 'X']]]);

            $this->assertArrayHasKey('columns', $errors, $relation);
        }

        $this->assertSame(1, Order::query()->count(), 'a model method named like a relation is never called');
    }

    public function test_the_lists_own_parameters_are_validated_with_the_usual_422(): void
    {
        $errors = $this->errors(['format' => 'csv', 'columns' => ['number' => 'N'], 'sort' => 'unknown_field']);

        $this->assertArrayHasKey('sort.unknown_field', $errors);
        Queue::assertNothingPushed();
    }

    public function test_an_empty_result_is_refused(): void
    {
        $errors = $this->errors(['format' => 'csv', 'columns' => ['number' => 'N'], 'filter' => ['number' => 'no-such']]);

        $this->assertSame(['There is no data to export.'], $errors['columns']);
        Queue::assertNothingPushed();
    }

    public function test_rows_over_the_cap_of_the_format_are_refused(): void
    {
        Order::create(['number' => 'B-1', 'total' => '1']);
        config(['export.max_rows.csv' => 1, 'export.max_rows.xlsx' => 5]);

        $this->assertArrayHasKey('format', $this->errors(['format' => 'csv', 'columns' => ['number' => 'N']]));
        $this->assertSame(2, $this->export(['format' => 'excel', 'columns' => ['number' => 'N']])->totalRows);
    }

    public function test_the_cap_counts_child_lines_not_documents(): void
    {
        OrderLine::create(['order_id' => Order::query()->first()->id, 'sku' => 's2', 'qty' => 1]);
        config(['export.max_rows.csv' => 1]);

        $this->assertArrayHasKey('format', $this->errors(['format' => 'csv', 'columns' => ['number' => 'N', 'lines' => ['sku' => 'S']]]));
        $this->assertSame(1, $this->export(['format' => 'csv', 'columns' => ['number' => 'N']])->totalRows);
    }

    public function test_a_rejected_request_does_not_keep_a_quota_slot(): void
    {
        config(['export.max_active_per_user' => 1]);

        $this->errors(['format' => 'csv', 'columns' => ['nope' => 'Nope']]);
        $this->errors(['format' => 'csv', 'columns' => ['number' => 'N'], 'filter' => ['number' => 'no-such']]);

        $task = $this->export(['format' => 'csv', 'columns' => ['number' => 'N']]);

        $this->assertTrue(Cache::has("export-slot:{$task->ownerId}:{$task->id}"));
    }

    public function test_the_owner_defaults_to_the_requests_user_and_is_required(): void
    {
        $this->actingAs($this->user);
        $task = (new OrderFilter(['format' => 'csv', 'columns' => ['number' => 'N']]))->export();
        $this->assertSame((string) $this->user->getKey(), $task->ownerId);

        $this->app['auth']->forgetGuards();
        $this->expectException(AuthenticationException::class);
        (new OrderFilter(['format' => 'csv', 'columns' => ['number' => 'N']]))->export();
    }

    public function test_no_finished_event_is_sent_for_a_request_that_is_only_queued(): void
    {
        Event::fake([ExportFinished::class]);

        $this->export(['format' => 'csv', 'columns' => ['number' => 'N']]);

        Event::assertNotDispatched(ExportFinished::class);
    }
}
