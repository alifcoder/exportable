<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Unit;

use Alif\Export\Exceptions\ExportException;
use Alif\Export\Tests\TestCaseWithoutDatabase;

final class ExportExceptionTest extends TestCaseWithoutDatabase
{
    public function test_client_facing_failures_map_to_http_statuses(): void
    {
        $this->assertSame(403, ExportException::forbidden()->httpStatus());
        $this->assertSame(429, ExportException::tooManyActive()->httpStatus());
    }

    public function test_client_facing_failures_are_answered_and_not_reported(): void
    {
        $exception = ExportException::tooManyActive();

        $this->assertTrue($exception->report(), 'true tells the Laravel handler the exception is already handled.');
        $this->assertSame(429, $exception->render()->getStatusCode());
        $this->assertSame('Too many active exports.', $exception->render()->getData(true)['message']);
    }

    public function test_operational_failures_keep_default_reporting_and_rendering(): void
    {
        foreach ([ExportException::ownerMissing(), ExportException::storageWriteFailed('disk full')] as $exception) {
            $this->assertNull($exception->httpStatus());
            $this->assertFalse($exception->report(), 'false falls back to the default reporting.');
            $this->assertFalse($exception->render());
        }
    }
}
