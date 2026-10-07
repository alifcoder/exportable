<?php

declare(strict_types=1);

namespace Alif\Export\Tests;

use Alif\Export\ExportServiceProvider;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\TestCase;

final class ProviderTest extends TestCase
{
    public function test_provider_is_loadable(): void
    {
        $this->assertTrue(is_subclass_of(ExportServiceProvider::class, ServiceProvider::class));
    }
}
