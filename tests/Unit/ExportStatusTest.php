<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Unit;

use Alif\Export\Enums\ExportStatus;
use Alif\Export\Tests\TestCaseWithoutDatabase;

final class ExportStatusTest extends TestCaseWithoutDatabase
{
    public function test_only_pending_and_processing_are_in_flight(): void
    {
        $this->assertTrue(ExportStatus::PENDING->isInFlight());
        $this->assertTrue(ExportStatus::PROCESSING->isInFlight());
        $this->assertFalse(ExportStatus::COMPLETED->isInFlight());
        $this->assertFalse(ExportStatus::FAILED->isInFlight());
        $this->assertSame(['pending', 'processing'], ExportStatus::inFlightValues());
    }
}
