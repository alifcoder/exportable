<?php

declare(strict_types=1);

namespace Alif\Export\Tests;

use Alif\Export\Providers\ExportServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [ExportServiceProvider::class];
    }
}
