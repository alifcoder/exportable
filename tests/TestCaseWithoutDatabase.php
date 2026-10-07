<?php

declare(strict_types=1);

namespace Alif\Export\Tests;

use Alif\Export\Providers\ExportServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/** For unit-level promises (writer, registry): no schema is created. */
abstract class TestCaseWithoutDatabase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ExportServiceProvider::class];
    }
}
