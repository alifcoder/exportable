<?php

declare(strict_types=1);

namespace Alif\Export;

use RuntimeException;

final class ExportException extends RuntimeException
{
    private function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function unknownExportable(string $key): self
    {
        return new self(sprintf('Unknown exportable "%s".', $key), 'unknown_exportable');
    }

    public static function invalidRegistration(string $message): self
    {
        return new self($message, 'invalid_registration');
    }

    public static function unknownColumn(string $key): self
    {
        return new self(sprintf('Column "%s" no longer exists in the export definition.', $key), 'unknown_column');
    }

    public static function rowLimitExceeded(int $cap): self
    {
        return new self(sprintf('Export exceeds the %d row limit.', $cap), 'row_limit_exceeded');
    }

    public static function forbidden(): self
    {
        return new self('The owner is no longer allowed to run this export.', 'forbidden');
    }

    public static function ownerMissing(): self
    {
        return new self('The export owner could not be resolved.', 'owner_missing');
    }

    public static function storageWriteFailed(string $disk, string $path): self
    {
        return new self(sprintf('Could not write the export file "%s" to disk "%s".', $path, $disk), 'storage_write_failed');
    }

    public static function invalidColumnValue(string $key): self
    {
        return new self(sprintf('Column "%s" produced a non-scalar value.', $key), 'invalid_column_value');
    }
}
