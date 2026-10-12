<?php

declare(strict_types=1);

namespace Alif\Export\Exceptions;

use RuntimeException;

final class ExportException extends RuntimeException
{
    private function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function invalidRegistration(string $message): self
    {
        return new self($message, 'invalid_registration');
    }

    public static function rowLimitExceeded(int $cap): self
    {
        return new self(sprintf('Export exceeds the %d row limit.', $cap), 'row_limit_exceeded');
    }

    public static function storageWriteFailed(string $reason): self
    {
        return new self(sprintf('Could not store the export file: %s.', $reason), 'storage_write_failed');
    }

    public static function invalidConfiguration(string $key): self
    {
        return new self(sprintf('Missing export configuration "export.%s".', $key), 'invalid_configuration');
    }

    public static function invalidColumnValue(string $key): self
    {
        return new self(sprintf('Column "%s" produced a non-scalar value.', $key), 'invalid_column_value');
    }
}
