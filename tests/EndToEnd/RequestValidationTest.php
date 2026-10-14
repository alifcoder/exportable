<?php

declare(strict_types=1);

namespace Alif\Export\Tests\EndToEnd;

use Alif\Export\Exceptions\ExportException;
use Illuminate\Database\Eloquent\Builder;

final class RequestValidationTest extends EndToEndTestCase
{
    /** @param array<string, mixed> $request */
    private function refused(array $request): ExportException
    {
        try {
            $this->export($request);
        } catch (ExportException $e) {
            $this->assertEmpty($this->finished, 'Nothing is queued when the request is refused.');
            $this->assertEmpty(self::$files);

            return $e;
        }

        $this->fail('The export should be refused.');
    }

    /** @param array<string, mixed> $request */
    private function assertClientError(array $request, string $code, string $field): ExportException
    {
        $e = $this->refused($request);

        $this->assertSame($code, $e->errorCode);
        $this->assertSame(422, $e->status);
        $this->assertTrue($e->isClientError());
        $this->assertArrayHasKey($field, $e->errors);

        return $e;
    }

    public function test_format_and_columns_are_required(): void
    {
        $this->seedOrders(1);

        $this->assertClientError(['columns' => ['number' => 'No']], 'invalid_request', 'format');
        $this->assertClientError(['format' => 'pdf', 'columns' => ['number' => 'No']], 'invalid_request', 'format');
        $this->assertClientError(['format' => 'csv'], 'invalid_request', 'columns');
        $this->assertClientError(['format' => 'csv', 'columns' => []], 'invalid_request', 'columns');
        $this->assertClientError(['format' => 'csv', 'columns' => 'number'], 'invalid_request', 'columns');
    }

    public function test_an_unknown_column_is_named(): void
    {
        $this->seedOrders(1);

        $e = $this->assertClientError(['format' => 'csv', 'columns' => ['number' => 'No', 'nope' => 'X', 'partner.nope' => 'Y']], 'unknown_column', 'columns');

        $this->assertCount(2, $e->errors['columns']);
        $this->assertStringContainsString('"nope"', $e->errors['columns'][0]);
        $this->assertStringContainsString('"partner.nope"', $e->errors['columns'][1]);
    }

    public function test_a_foreign_key_column_is_exported_like_any_other_the_resource_yields(): void
    {
        $this->seedOrders(1, [], lines: 1);

        $this->export(['format' => 'csv', 'columns' => ['partner_id' => 'P', 'partner.region_id' => 'R']]);

        $this->assertCount(1, $this->finished);
        $this->assertTrue($this->finished[0]->succeeded());
    }

    public function test_lists_nest_at_most_five_levels(): void
    {
        $this->seedOrders(1);

        $this->assertClientError(['format' => 'csv', 'columns' => [
            'number' => 'N', 'a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => 'G']]]]]],
        ]], 'nested_too_deep', 'columns');
    }

    public function test_an_empty_selection_is_refused(): void
    {
        $this->seedOrders(3);

        $this->assertClientError(['format' => 'csv', 'columns' => ['number' => 'No'], 'filter' => ['none' => 1]], 'no_data', 'columns');
    }

    public function test_more_rows_than_the_format_allows_are_refused_before_queueing(): void
    {
        config(['export.max_rows.csv' => 5, 'export.max_rows.xlsx' => 100]);
        $this->seedOrders(10);

        $this->assertClientError(['format' => 'csv', 'columns' => ['number' => 'No']], 'too_many_rows', 'format');

        $this->export(['format' => 'excel', 'columns' => ['number' => 'No']]);
        $this->assertCount(1, $this->finished);
    }

    public function test_a_query_the_job_cannot_page_is_refused(): void
    {
        $this->seedOrders(3);

        OrderFilter::$sort = [];
        $e = $this->refused(['format' => 'csv', 'columns' => ['number' => 'No']]);
        $this->assertSame('unpageable_query', $e->errorCode);
        $this->assertSame(500, $e->status);
        $this->assertStringContainsString('ORDER BY', $e->getMessage());

        OrderFilter::$sort = ['id'];
        OrderFilter::$grouped = true;
        $e = $this->refused(['format' => 'csv', 'columns' => ['number' => 'No']]);
        $this->assertSame('unpageable_query', $e->errorCode);
        $this->assertStringContainsString('group', $e->getMessage());

        OrderFilter::$grouped = false;
        OrderFilter::$union = true;
        $e = $this->refused(['format' => 'csv', 'columns' => ['number' => 'No']]);
        $this->assertSame('unpageable_query', $e->errorCode);
    }

    public function test_a_constrained_eager_load_is_refused(): void
    {
        $this->seedOrders(1);
        $filter = new class(['format' => 'csv', 'columns' => ['number' => 'No']]) extends OrderFilter
        {
            public function apply(Builder $query): void
            {
                $query->with(['lines' => fn ($q) => $q->where('quantity', '>', 0)]);
            }
        };

        try {
            $filter->export();
            $this->fail('A constrained eager load cannot be repeated by the job.');
        } catch (ExportException $e) {
            $this->assertSame('unsupported_eager_load', $e->errorCode);
            $this->assertSame(500, $e->status);
        }
    }

    public function test_the_messages_are_translated(): void
    {
        $this->seedOrders(1);

        foreach (['uz' => 'Noma\'lum ustun: "nope".', 'ru' => 'Неизвестная колонка: "nope".', 'en' => 'Unknown column "nope".'] as $locale => $message) {
            app()->setLocale($locale);
            $e = $this->refused(['format' => 'csv', 'columns' => ['nope' => 'X']]);
            $this->assertSame($message, $e->getMessage());
        }
    }

    public function test_the_request_parameters_of_a_page_are_ignored(): void
    {
        $this->seedOrders(25);

        foreach ([['limit' => 3], ['limit' => 3, 'offset' => 10], ['paginate' => 1, 'per_page' => 4, 'page' => 2]] as $i => $page) {
            $this->finished = [];
            $this->export(['format' => 'csv', 'columns' => ['number' => 'No'], ...$page]);

            $this->assertSame(25, $this->finished[0]->rows, 'Case '.$i);
        }
    }
}
