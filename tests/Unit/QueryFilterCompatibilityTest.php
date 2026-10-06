<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Unit;

use Alif\Export\ExportException;
use Alif\Export\Support\QueryFilterCompatibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryFilterCompatibilityTest extends TestCase
{
    /** @return array<string, array{?string}> */
    public static function unsupported(): array
    {
        return ['below' => ['2.0.0'], 'above' => ['3.0.0'], 'dev' => ['dev-main'], 'dev-alias' => ['2.1.x-dev'], 'prerelease' => ['2.1.0-beta1'], 'rc' => ['v2.2.0-RC1']];
    }

    #[DataProvider('unsupported')]
    public function test_unsupported_versions_throw(?string $version): void
    {
        $this->expectException(ExportException::class);
        $this->expectExceptionMessage('>=2.0.1 <3.0.0');

        QueryFilterCompatibility::assertCompatible($version);
    }

    #[DataProvider('supported')]
    public function test_supported_versions_pass(string $version): void
    {
        QueryFilterCompatibility::assertCompatible($version);

        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{string}> */
    public static function supported(): array
    {
        return ['min' => ['2.0.1'], 'prefixed' => ['v2.9.9'], 'max' => ['2.9.9']];
    }

    public function test_missing_version_is_unsupported(): void
    {
        $this->assertFalse(QueryFilterCompatibility::isSupported(null));
    }

    public function test_installed_version_is_supported(): void
    {
        QueryFilterCompatibility::assertCompatible();

        $this->addToAssertionCount(1);
    }
}
