<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Alif\Export\Exceptions\ExportException;

/** Reads `config/export.php`. The package ships no defaults: a missing key is a host configuration error. */
final class ExportConfig
{
    public static function get(string $key): mixed
    {
        $value = config("export.$key");

        return $value ?? throw ExportException::invalidConfiguration($key);
    }

    /** A key that must be present but may be null (e.g. the default queue connection). */
    public static function nullable(string $key): mixed
    {
        return config()->has("export.$key") ? config("export.$key") : throw ExportException::invalidConfiguration($key);
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    public static function bool(string $key): bool
    {
        return (bool) self::get($key);
    }

    public static function string(string $key): string
    {
        return (string) self::get($key);
    }

    /** @return array<string, mixed> */
    public static function array(string $key): array
    {
        return (array) self::get($key);
    }
}
