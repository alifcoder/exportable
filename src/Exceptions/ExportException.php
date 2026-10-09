<?php

declare(strict_types=1);

namespace Alif\Export\Exceptions;

use Illuminate\Http\JsonResponse;
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

    public static function storageWriteFailed(string $reason): self
    {
        return new self(sprintf('Could not store the export file: %s.', $reason), 'storage_write_failed');
    }

    public static function invalidConfiguration(string $key): self
    {
        return new self(sprintf('Missing export configuration "export.%s".', $key), 'invalid_configuration');
    }

    public static function tooManyActive(): self
    {
        return new self('Too many active exports.', 'too_many_active');
    }

    public static function invalidColumnValue(string $key): self
    {
        return new self(sprintf('Column "%s" produced a non-scalar value.', $key), 'invalid_column_value');
    }

    /** HTTP status of a failure the caller caused or can resolve; null for operational failures. */
    public function httpStatus(): ?int
    {
        return match ($this->errorCode) {
            'forbidden' => 403,
            'too_many_active' => 429,
            default => null,
        };
    }

    /** Laravel reports by default only when this returns false: client-facing failures are answered, not reported. */
    public function report(): bool
    {
        return $this->httpStatus() !== null;
    }

    public function render(): JsonResponse|false
    {
        $status = $this->httpStatus();

        return $status === null ? false : response()->json(['message' => $this->getMessage()], $status);
    }
}
