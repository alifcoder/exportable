<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Alif\Export\Exceptions\ExportException;

/** Reads `config/export.php`. The package ships no defaults: a missing key is a host configuration error. */
final class ExportConfig
{
    /** Reads a setting. A missing setting is an error. */
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

    /** Reads a setting as an integer. */
    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    /** Reads a setting as true or false. */
    public static function bool(string $key): bool
    {
        return (bool) self::get($key);
    }

    /** Reads a setting as text. */
    public static function string(string $key): string
    {
        return (string) self::get($key);
    }
}
