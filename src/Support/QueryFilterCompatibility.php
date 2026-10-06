<?php

declare(strict_types=1);

namespace Alif\Export\Support;

use Alif\Export\ExportException;
use Composer\InstalledVersions;

final class QueryFilterCompatibility
{
    public const string PACKAGE = 'alifcoder/query-filter';

    public const string MIN = '2.0.1';

    public const string MAX_EXCLUSIVE = '3.0.0';

    /** @throws ExportException */
    public static function assertCompatible(?string $version = null): void
    {
        $found = $version ?? InstalledVersions::getVersion(self::PACKAGE);

        if (! self::isSupported($found)) {
            throw ExportException::incompatibleQueryFilter(
                $found,
                sprintf('>=%s <%s', self::MIN, self::MAX_EXCLUSIVE),
            );
        }
    }

    public static function isSupported(?string $version): bool
    {
        // Stable releases only: rejects dev branches, "-dev" aliases and pre-release suffixes.
        if ($version === null || ! preg_match('/^v?\d+\.\d+\.\d+(\.\d+)?$/', $version)) {
            return false;
        }

        $normalized = ltrim($version, 'v');

        return version_compare($normalized, self::MIN, '>=')
            && version_compare($normalized, self::MAX_EXCLUSIVE, '<');
    }
}
