<?php

declare(strict_types=1);

namespace Alif\Export\Tests;

use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\DTO\Export\ExportDTO;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Services\Interfaces\ExportServiceInterface;
use Alif\Export\Tests\Fixtures\DocFilter;
use Illuminate\Support\Arr;

final class ColumnValidationTest extends TestCase
{
    private ?ExportDTO $received = null;

    protected function setUp(): void
    {
        parent::setUp();

        $test = $this;
        $this->app->bind(ExportServiceInterface::class, fn () => new class($test) implements ExportServiceInterface
        {
            public function __construct(private readonly ColumnValidationTest $test) {}

            public function create(ExportDTO $request): ExportTask
            {
                $this->test->capture($request);

                return new ExportTask('id', $this->test->dummyDto(), null);
            }
        });
    }

    public function capture(ExportDTO $dto): void
    {
        $this->received = $dto;
    }

    public function dummyDto(): ExportCreateDTO
    {
        return (new \ReflectionClass(ExportCreateDTO::class))->newInstanceWithoutConstructor();
    }

    /** @param array<string, mixed> $columns */
    private function export(array $columns): void
    {
        (new DocFilter(['format' => 'csv', 'columns' => $columns]))->export();
    }

    /** @param array<string, mixed> $columns @return list<string> */
    private function errors(array $columns): array
    {
        try {
            $this->export($columns);
        } catch (ExportException $e) {
            return $e->errors['columns'] ?? [];
        }

        return [];
    }

    public function test_accepts_document_columns_nested_objects_and_lists(): void
    {
        $this->export([
            'number' => 'No',
            'partner.name' => 'Partner',
            'date' => 'Date',
            'lines' => ['quantity' => 'Qty', 'product.name' => 'Product', 'serials' => ['imei' => 'IMEI']],
        ]);

        $this->assertNotNull($this->received);
        $this->assertCount(6, $this->received->columns);
        $this->assertSame(['lines', 'serials'], $this->received->columns[5]->groups);
    }

    public function test_refuses_a_document_column_that_is_not_a_field(): void
    {
        $this->assertSame(['Column "secret" is not offered by the filter.'], $this->errors(['number' => 'No', 'secret' => 'S']));
        $this->assertNull($this->received);
    }

    public function test_refuses_unknown_list_item_and_inner_list_item(): void
    {
        $errors = $this->errors([
            'number' => 'No',
            'lines' => ['quantity' => 'Q', 'cost' => 'C', 'serials' => ['imei' => 'I', 'bogus' => 'B']],
        ]);

        $this->assertSame([
            'Column "lines.cost" is not offered by the filter.',
            'Column "lines.serials.bogus" is not offered by the filter.',
        ], $errors);
    }

    public function test_accepts_a_list_the_filter_does_not_name_and_leaves_it_unchecked(): void
    {
        $this->assertSame([], $this->errors(['number' => 'No', 'approvals' => ['status' => 'S']]));
        $this->assertSame(['approvals'], $this->received->columns[1]->groups);
    }

    public function test_a_list_inside_another_lists_items_is_accepted_unchecked(): void
    {
        $this->assertSame([], $this->errors(['number' => 'No', 'lines' => ['quantity' => 'Q', 'notes' => ['text' => 'T']]]));
        $this->assertSame(['lines', 'notes'], $this->received->columns[2]->groups);
    }

    public function test_an_item_filter_class_key_is_read_as_an_unchecked_list(): void
    {
        $this->assertSame([], $this->errors(['number' => 'No', 'lines' => ['quantity' => 'Q', '0' => ['imei' => 'I']]]));
    }

    public function test_needs_a_document_column(): void
    {
        $this->assertSame(
            [__('export::messages.needs_document_column')],
            $this->errors(['lines' => ['quantity' => 'Q']]),
        );
    }

    public function test_limits_the_depth_of_lists(): void
    {
        $columns = ['number' => 'No', 'lines' => ['serials' => ['serials' => ['serials' => ['serials' => ['serials' => ['imei' => 'I']]]]]]];

        $this->assertSame([__('export::messages.nested_too_deep', ['max' => 5])], $this->errors($columns));
    }

    public function test_messages_are_translated(): void
    {
        foreach (['uz', 'ru', 'en'] as $locale) {
            $this->app->setLocale($locale);
            $message = $this->errors(['number' => 'No', 'secret' => 'S'])[0];

            $this->assertStringContainsString('secret', $message);
            $this->assertStringNotContainsString('export::', $message);
        }

        $this->app->setLocale('uz');
        $this->assertSame('"secret" ustuni filterda mavjud emas.', $this->errors(['number' => 'No', 'secret' => 'S'])[0]);
    }

    public function test_every_language_has_every_message(): void
    {
        $keys = fn (string $l): array => array_keys(Arr::dot(require __DIR__."/../lang/$l/messages.php"));
        $this->assertSame($keys('en'), $keys('uz'));
        $this->assertSame($keys('en'), $keys('ru'));
    }
}
