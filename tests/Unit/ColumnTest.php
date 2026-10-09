<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Unit;

use Alif\Export\Helpers\Column;
use Alif\Export\Tests\Fixtures\Order;
use PHPUnit\Framework\TestCase;

final class ColumnTest extends TestCase
{
    public function test_defaults_are_text_without_relations(): void
    {
        $column = Column::make('Label');

        $this->assertSame('Label', $column->label());
        $this->assertFalse($column->isNumeric());
        $this->assertSame([], $column->getRelations());
    }

    public function test_numeric_toggles(): void
    {
        $this->assertTrue(Column::make('x')->numeric()->isNumeric());
        $this->assertFalse(Column::make('x')->numeric()->numeric(false)->isNumeric());
    }

    public function test_relations_are_deduplicated_and_accumulate_across_calls(): void
    {
        $column = Column::make('x')->relations('a', 'b', 'a')->relations('b', 'c.d');

        $this->assertSame(['a' => null, 'b' => null, 'c.d' => null], $column->getRelations());
    }

    public function test_without_closure_resolves_the_attribute_named_by_the_key(): void
    {
        $order = new Order(['number' => 'A-1']);

        $this->assertSame('A-1', Column::make('x')->resolve($order, 'number'));
        $this->assertNull(Column::make('x')->resolve($order, 'missing'));
    }

    public function test_without_closure_supports_dot_notation(): void
    {
        $order = new Order(['number' => 'A']);
        $order->setRelation('self', new Order(['number' => 'nested']));

        $this->assertSame('nested', Column::make('x')->resolve($order, 'self.number'));
    }

    public function test_closure_wins_over_the_key(): void
    {
        $order = new Order(['number' => 'A-1']);

        $this->assertSame('A-1!', Column::make('x', fn (Order $o): string => $o->number.'!')->resolve($order, 'ignored'));
    }
}
