<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Unit;

use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\Enums\ExportFormat;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportPlan;
use Alif\Export\Tests\Fixtures\OrderExportable;
use Alif\Export\Tests\Fixtures\PlainOrderExportable;
use Alif\Export\Tests\Fixtures\TranslatedOrderExportable;
use Alif\Export\Tests\TestCaseWithoutDatabase;

final class ExportPlanTest extends TestCaseWithoutDatabase
{
    /** @param list<string> $columns @param list<string> $child */
    private function dto(array $columns, bool $children = false, array $child = []): ExportCreateDTO
    {
        return new ExportCreateDTO('orders', ExportFormat::CSV, $columns, $children, $child, null, []);
    }

    public function test_columns_follow_the_requested_order_not_the_definition_order(): void
    {
        $plan = ExportPlan::for(new OrderExportable, $this->dto(['total', 'number']));

        $this->assertSame(['total', 'number'], array_keys($plan->columns));
        $this->assertSame(['Total', 'Number'], $plan->headings());
        $this->assertSame([true, false], $plan->numeric());
    }

    public function test_children_are_appended_after_document_columns(): void
    {
        $plan = ExportPlan::for(new OrderExportable, $this->dto(['number'], true, ['qty', 'sku']));

        $this->assertSame('lines', $plan->childRelation);
        $this->assertSame(['Number', 'Qty', 'SKU'], $plan->headings());
        $this->assertSame([false, true, false], $plan->numeric());
    }

    public function test_child_columns_are_ignored_when_children_are_not_included(): void
    {
        $plan = ExportPlan::for(new OrderExportable, $this->dto(['number'], false, ['sku']));

        $this->assertNull($plan->childRelation);
        $this->assertSame([], $plan->childColumns);
        $this->assertSame(['Number'], $plan->headings());
    }

    public function test_children_requested_on_a_document_without_a_relation_are_ignored(): void
    {
        $plan = ExportPlan::for(new PlainOrderExportable, $this->dto(['number'], true, []));

        $this->assertNull($plan->childRelation);
        $this->assertSame([], $plan->childColumns);
    }

    public function test_unknown_document_column_throws_unknown_column(): void
    {
        try {
            ExportPlan::for(new OrderExportable, $this->dto(['number', 'nope']));
            $this->fail('Expected ExportException');
        } catch (ExportException $e) {
            $this->assertSame('unknown_column', $e->errorCode);
            $this->assertStringContainsString('"nope"', $e->getMessage());
        }
    }

    public function test_unknown_child_column_throws_unknown_column(): void
    {
        $this->expectException(ExportException::class);
        $this->expectExceptionMessage('"nope"');

        ExportPlan::for(new OrderExportable, $this->dto(['number'], true, ['nope']));
    }

    public function test_headings_are_translated_in_the_current_locale(): void
    {
        app('translator')->addLines(['messages.col_number' => 'Raqam', 'messages.col_total' => 'Jami'], 'uz');
        app()->setLocale('uz');

        $plan = ExportPlan::for(new TranslatedOrderExportable, $this->dto(['number', 'total']));

        $this->assertSame(['Raqam', 'Jami'], $plan->headings());
    }

    public function test_untranslated_labels_fall_back_to_the_literal_text(): void
    {
        $plan = ExportPlan::for(new TranslatedOrderExportable, $this->dto(['number']));

        $this->assertSame(['messages.col_number'], $plan->headings());
    }

    public function test_empty_column_list_gives_an_empty_plan(): void
    {
        $plan = ExportPlan::for(new OrderExportable, $this->dto([]));

        $this->assertSame([], $plan->headings());
        $this->assertSame([], $plan->numeric());
    }
}
