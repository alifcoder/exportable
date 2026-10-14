<?php

declare(strict_types=1);

namespace Alif\Export\Exceptions;

use Alif\Export\Enums\ExportFormatEnum;
use RuntimeException;
use Throwable;

/**
 * The error of an export. Every error has a short `errorCode` the host can read, an HTTP `status` (422 when the caller
 * can fix the request, 500 otherwise) and, for a 422, `errors` keyed by request field (`columns`, `format`).
 */
final class ExportException extends RuntimeException
{
    /**
     * Private: create the error with one of the named methods below.
     *
     * @param  array<string, list<string>>  $errors
     */
    private function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 500,
        public readonly array $errors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** True when the caller can fix the request (HTTP 422); false for a set-up or runtime failure (HTTP 500). */
    public function isClientError(): bool
    {
        return $this->status < 500;
    }

    /**
     * `format` or `columns` are missing or invalid, or the filter's own export rules fail.
     *
     * @param  array<string, list<string>>  $errors  Messages by request field.
     */
    public static function invalidRequest(array $errors): self
    {
        return new self(__('export::messages.invalid_request'), 'invalid_request', 422, $errors);
    }

    /** Lists are nested deeper than allowed. */
    public static function nestedTooDeep(int $max): self
    {
        $message = __('export::messages.nested_too_deep', ['max' => $max]);

        return new self($message, 'nested_too_deep', 422, ['columns' => [$message]]);
    }

    /**
     * Columns the resource does not yield.
     *
     * @param  list<string>  $columns  Dotted paths.
     */
    public static function unknownColumns(array $columns): self
    {
        $messages = array_map(fn (string $column): string => __('export::messages.unknown_column', ['column' => $column]), $columns);

        return new self($messages[0] ?? __('export::messages.invalid_request'), 'unknown_column', 422, ['columns' => $messages]);
    }

    /** The query selects no row. */
    public static function noData(): self
    {
        $message = __('export::messages.no_data');

        return new self($message, 'no_data', 422, ['columns' => [$message]]);
    }

    /** The export has more rows than the format allows (checked before the job exists). */
    public static function tooManyRows(ExportFormatEnum $format, int $max): self
    {
        $message = __('export::messages.too_many_rows', ['format' => $format->value, 'max' => $max]);

        return new self($message, 'too_many_rows', 422, ['format' => [$message]]);
    }

    /** The host's query cannot be read in chunks (it has a limit or offset, or no ORDER BY). */
    /** @param  string  $reason  A key of `export::messages.unpageable.*`. */
    public static function unpageableQuery(string $reason): self
    {
        return new self(__('export::messages.unpageable_query', ['reason' => __('export::messages.unpageable.'.$reason)]), 'unpageable_query');
    }

    /** The host's query has an eager load with a constraint, which the job cannot repeat. */
    public static function unsupportedEagerLoad(string $relation): self
    {
        return new self(__('export::messages.unsupported_eager_load', ['relation' => $relation]), 'unsupported_eager_load');
    }

    /** The resource threw while rendering a row. */
    public static function resourceFailed(string $resource, Throwable $previous): self
    {
        return new self(__('export::messages.resource_failed', ['resource' => $resource, 'reason' => $previous->getMessage()]), 'resource_failed', 500, [], $previous);
    }

    /**
     * The export is set up wrongly (for example, an unsupported database driver).
     *
     * @param  string  $reason  A key of `export::messages.registration.*`.
     * @param  array<string, scalar>  $replace
     */
    public static function invalidRegistration(string $reason, array $replace = []): self
    {
        return new self(__('export::messages.invalid_registration_detail', ['reason' => __('export::messages.registration.'.$reason, $replace)]), 'invalid_registration');
    }

    /** The export has more rows than the format allows (found while writing). */
    public static function rowLimitExceeded(int $cap): self
    {
        return new self(__('export::messages.row_limit_exceeded', ['max' => $cap]), 'row_limit_exceeded');
    }

    /** A file could not be written or stored. */
    public static function storageWriteFailed(string $reason): self
    {
        return new self(__('export::messages.storage_write_failed', ['reason' => $reason]), 'storage_write_failed');
    }

    /** A setting is missing in `config/export.php`. */
    public static function invalidConfiguration(string $key): self
    {
        return new self(__('export::messages.invalid_configuration', ['key' => $key]), 'invalid_configuration');
    }
}
