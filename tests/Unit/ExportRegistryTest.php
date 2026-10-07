<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Unit;

use Alif\Export\ExportException;
use Alif\Export\ExportRegistry;
use Alif\Export\Tests\Fixtures\OrderExportable;
use Alif\Export\Tests\Fixtures\PlainOrderExportable;
use Alif\Export\Tests\TestCaseWithoutDatabase;
use stdClass;

final class ExportRegistryTest extends TestCaseWithoutDatabase
{
    private function registry(): ExportRegistry
    {
        return new ExportRegistry(app());
    }

    public function test_valid_registration_resolves_an_exportable(): void
    {
        $registry = $this->registry();
        $registry->register('sale.sales-1_x', OrderExportable::class);

        $this->assertTrue($registry->has('sale.sales-1_x'));
        $this->assertInstanceOf(OrderExportable::class, $registry->get('sale.sales-1_x'));
    }

    public function test_bad_key_format_is_rejected(): void
    {
        foreach (['', 'Has Space', 'UPPER', 'a/b', 'x;y', str_repeat('a', 101)] as $key) {
            try {
                $this->registry()->register($key, OrderExportable::class);
                $this->fail('Key accepted: '.$key);
            } catch (ExportException $e) {
                $this->assertSame('invalid_registration', $e->errorCode);
            }
        }
    }

    public function test_duplicate_key_is_rejected(): void
    {
        $registry = $this->registry();
        $registry->register('orders', OrderExportable::class);

        $this->expectException(ExportException::class);
        $registry->register('orders', PlainOrderExportable::class);
    }

    public function test_class_not_implementing_exportable_is_rejected(): void
    {
        $this->expectException(ExportException::class);

        $this->registry()->register('orders', stdClass::class);
    }

    public function test_unknown_key_throws_unknown_exportable(): void
    {
        $registry = $this->registry();

        $this->assertFalse($registry->has('nope'));

        try {
            $registry->get('nope');
            $this->fail('Expected ExportException');
        } catch (ExportException $e) {
            $this->assertSame('unknown_exportable', $e->errorCode);
        }
    }

    public function test_keys_lists_every_registered_key(): void
    {
        $registry = new ExportRegistry($this->app);
        $registry->register('a.one', OrderExportable::class);
        $registry->register('b.two', OrderExportable::class);

        $this->assertSame(['a.one', 'b.two'], $registry->keys());
    }
}
