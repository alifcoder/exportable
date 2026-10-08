<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Unit;

use Alif\Export\Tests\Fixtures\OrderExportable;
use Alif\Export\Tests\Fixtures\PlainOrderExportable;
use Alif\Export\Tests\Fixtures\TranslatedOrderExportable;
use Alif\Export\Tests\TestCaseWithoutDatabase;
use Alif\Export\Transformers\Export\ExportDefinitionResource;
use Illuminate\Http\Request;

final class ExportDefinitionResourceTest extends TestCaseWithoutDatabase
{
    public function test_shape_has_key_title_formats_columns_and_child_columns(): void
    {
        config(['export.max_rows.csv' => 11, 'export.max_rows.xlsx' => 22]);

        $data = (new ExportDefinitionResource(new OrderExportable, 'orders'))->toArray(Request::create('/'));

        $this->assertSame(['key', 'title', 'formats', 'columns', 'child_columns'], array_keys($data));
        $this->assertSame('orders', $data['key']);
        $this->assertSame('Orders', $data['title']);
        $this->assertSame(['csv' => 11, 'xlsx' => 22], $data['formats']);
        $this->assertSame(['number', 'total', 'lines_count', 'broken', 'undeclared'], array_column($data['columns'], 'key'));
        $this->assertSame(['key' => 'number', 'label' => 'Number'], $data['columns'][0]);
        $this->assertSame([['key' => 'sku', 'label' => 'SKU'], ['key' => 'qty', 'label' => 'Qty']], $data['child_columns']);
    }

    public function test_lists_are_sequential_json_arrays(): void
    {
        $data = (new ExportDefinitionResource(new OrderExportable, 'orders'))->toArray(Request::create('/'));

        $this->assertTrue(array_is_list($data['columns']));
        $this->assertTrue(array_is_list($data['child_columns']));
    }

    public function test_document_without_children_has_empty_child_columns(): void
    {
        $data = (new ExportDefinitionResource(new PlainOrderExportable, 'plain'))->toArray(Request::create('/'));

        $this->assertSame([], $data['child_columns']);
        $this->assertSame('plain', $data['key']);
    }

    public function test_title_and_labels_are_translated_in_the_request_locale(): void
    {
        app('translator')->addLines([
            'messages.orders_title' => 'Buyurtmalar',
            'messages.col_number' => 'Raqam',
            'messages.col_sku' => 'Artikul',
        ], 'uz');
        app()->setLocale('uz');

        $data = (new ExportDefinitionResource(new TranslatedOrderExportable, 'orders'))->toArray(Request::create('/'));

        $this->assertSame('Buyurtmalar', $data['title']);
        $this->assertSame('Raqam', $data['columns'][0]['label']);
        $this->assertSame('Artikul', $data['child_columns'][0]['label']);
        $this->assertSame('messages.col_total', $data['columns'][1]['label'], 'missing translation falls back to the key');
    }

    public function test_resolves_to_json_without_wrapping_issues(): void
    {
        $json = (new ExportDefinitionResource(new OrderExportable, 'orders'))->toResponse(Request::create('/'))->getData(true);

        $this->assertSame('orders', $json['data']['key']);
    }
}
