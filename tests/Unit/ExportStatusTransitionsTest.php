<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Unit;

use Alif\Export\Enums\ExportStatus;
use PHPUnit\Framework\TestCase;

final class ExportStatusTransitionsTest extends TestCase
{
    public function test_in_flight_values_are_pending_and_processing_in_declaration_order(): void
    {
        $this->assertSame(['pending', 'processing'], ExportStatus::inFlightValues());
    }

    public function test_terminal_states_are_not_in_flight(): void
    {
        $this->assertFalse(ExportStatus::COMPLETED->isInFlight());
        $this->assertFalse(ExportStatus::FAILED->isInFlight());
    }

    public function test_status_values_are_the_stable_wire_strings(): void
    {
        $this->assertSame(
            ['pending', 'processing', 'completed', 'failed'],
            array_map(fn (ExportStatus $s): string => $s->value, ExportStatus::cases()),
        );
        $this->assertNull(ExportStatus::tryFrom('done'));
    }
}
