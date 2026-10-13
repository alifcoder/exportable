<?php

declare(strict_types=1);

namespace Alif\Export\Exceptions;

use RuntimeException;

/** The error of an export. Every error has a short `errorCode` that the host can read. */
final class ExportException extends RuntimeException
{
    /** Private: create the error with one of the named methods below. */
    private function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }

    /** The export is set up wrongly (for example, an unsupported database driver). */
    public static function invalidRegistration(string $message): self
    {
        return new self($message, 'invalid_registration');
    }

    /** The export has more rows than the format allows. */
    public static function rowLimitExceeded(int $cap): self
    {
        return new self(sprintf('Export exceeds the %d row limit.', $cap), 'row_limit_exceeded');
    }

    /** A file could not be written or stored. */
    public static function storageWriteFailed(string $reason): self
    {
        return new self(sprintf('Could not store the export file: %s.', $reason), 'storage_write_failed');
    }

    /** A setting is missing in `config/export.php`. */
    public static function invalidConfiguration(string $key): self
    {
        return new self(sprintf('Missing export configuration "export.%s".', $key), 'invalid_configuration');
    }

    /** A column gave an array or an object, but a cell can hold only a simple value. */
    public static function invalidColumnValue(string $key): self
    {
        return new self(sprintf('Column "%s" produced a non-scalar value.', $key), 'invalid_column_value');
    }
}
