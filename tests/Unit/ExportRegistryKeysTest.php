<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Unit;

use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportRegistry;
use Alif\Export\Tests\Fixtures\OrderExportable;
use Alif\Export\Tests\Fixtures\PlainOrderExportable;
use Alif\Export\Tests\TestCaseWithoutDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

final class ExportRegistryKeysTest extends TestCaseWithoutDatabase
{
    /** @return array<string, array{string}> */
    public static function validKeys(): array
    {
        return [
            'lowercase' => ['orders'],
            'digits' => ['2024'],
            'underscore' => ['sales_orders'],
            'dot' => ['sales.orders'],
            'dash' => ['sales-orders'],
            'one char' => ['a'],
            '100 chars' => [str_repeat('a', 100)],
        ];
    }

    /** @return array<string, array{string}> */
    public static function invalidKeys(): array
    {
        return [
            'empty' => [''],
            'uppercase' => ['Orders'],
            'space' => ['my orders'],
            'slash' => ['a/b'],
            '101 chars' => [str_repeat('a', 101)],
            'unicode' => ['buyurtmalar_ў'],
            'newline suffix' => ["orders\n"],
            'path traversal' => ['../orders'],
        ];
    }

    #[DataProvider('validKeys')]
    public function test_valid_keys_register(string $key): void
    {
        $registry = new ExportRegistry($this->app);
        $registry->register($key, OrderExportable::class);

        $this->assertTrue($registry->has($key));
        $this->assertInstanceOf(OrderExportable::class, $registry->get($key));
    }

    #[DataProvider('invalidKeys')]
    public function test_invalid_keys_are_rejected_as_invalid_registration(string $key): void
    {
        $registry = new ExportRegistry($this->app);

        try {
            $registry->register($key, OrderExportable::class);
            $this->fail('Expected ExportException');
        } catch (ExportException $e) {
            $this->assertSame('invalid_registration', $e->errorCode);
        }
        $this->assertSame([], $registry->keys());
    }

    public function test_has_is_false_for_unregistered_keys(): void
    {
        $registry = new ExportRegistry($this->app);

        $this->assertFalse($registry->has('orders'));
        $this->assertFalse($registry->has(''));
    }

    public function test_unknown_class_name_is_rejected(): void
    {
        $this->expectException(ExportException::class);

        (new ExportRegistry($this->app))->register('x', 'No\\Such\\Class');
    }

    public function test_non_exportable_class_leaves_the_registry_unchanged(): void
    {
        $registry = new ExportRegistry($this->app);

        try {
            $registry->register('x', stdClass::class);
        } catch (ExportException) {
        }

        $this->assertFalse($registry->has('x'));
    }

    public function test_duplicate_key_keeps_the_first_class(): void
    {
        $registry = new ExportRegistry($this->app);
        $registry->register('orders', OrderExportable::class);

        try {
            $registry->register('orders', PlainOrderExportable::class);
            $this->fail('Expected ExportException');
        } catch (ExportException $e) {
            $this->assertStringContainsString('already registered', $e->getMessage());
        }

        $this->assertInstanceOf(OrderExportable::class, $registry->get('orders'));
    }

    public function test_get_resolves_a_fresh_instance_through_the_container(): void
    {
        $registry = new ExportRegistry($this->app);
        $registry->register('orders', OrderExportable::class);

        $this->assertNotSame($registry->get('orders'), $registry->get('orders'));
    }

    public function test_keys_keep_registration_order(): void
    {
        $registry = new ExportRegistry($this->app);
        $registry->register('b', OrderExportable::class);
        $registry->register('a', PlainOrderExportable::class);

        $this->assertSame(['b', 'a'], $registry->keys());
    }

    public function test_bad_config_registration_fails_when_the_registry_is_resolved(): void
    {
        config(['export.exportables' => ['Bad Key' => OrderExportable::class]]);
        $this->app->forgetInstance(ExportRegistry::class);

        $this->expectException(ExportException::class);
        app(ExportRegistry::class);
    }
}
