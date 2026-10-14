<?php

declare(strict_types=1);

namespace Alif\Export\Helpers\Writers;

use Alif\Export\Helpers\CellType;
use Alif\QueryFilter\Enums\FieldType;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * One cell value with the type it is written as: the column's declared type, or the type of the value itself.
 * A value that does not fit its type (text in a number column, a number too long for Excel) is written as exact
 * text, never lost or rounded.
 */
final readonly class TypedValue
{
    /** Excel keeps about 15 significant digits; longer numbers are written as exact text. */
    private const int MAX_SAFE_DIGITS = 15;

    /** @param mixed $raw The value as given: csv writes a number exactly as it came (`5.250000`, `1.5e3`). */
    private function __construct(public FieldType $type, public mixed $value, public mixed $raw = null) {}

    /** Gives a value its type: the column's declared type or, when there is none, the type of the value itself. */
    public static function of(?FieldType $declared, mixed $value): self
    {
        if ($value === null || $value === '') {
            return new self(FieldType::String, '');
        }

        return match (true) {
            $declared === null => self::inferred($value),
            CellType::isNumeric($declared) => self::number($declared, $value),
            $declared === FieldType::Boolean => self::boolean($value),
            CellType::isTemporal($declared) => self::temporal($declared, $value),
            default => new self(FieldType::String, (string) $value),
        };
    }

    /** Finds the type from the value itself (no declared type). */
    private static function inferred(mixed $value): self
    {
        return match (true) {
            is_bool($value) => new self(FieldType::Boolean, $value),
            $value instanceof CarbonInterface => new self(FieldType::DateTime, $value),
            is_int($value), is_float($value) => self::number(null, $value),
            default => new self(FieldType::String, (string) $value),
        };
    }

    /** Makes a number cell, of the declared type or (none) whole or decimal by the value. Text or a number that is too long stays as exact text. */
    private static function number(?FieldType $type, mixed $value): self
    {
        $isNumber = (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)))
            && self::digits((string) $value) <= self::MAX_SAFE_DIGITS;

        if (! $isNumber) {
            return new self(FieldType::String, (string) $value);
        }

        $number = $value + 0;

        return new self($type ?? (is_int($number) ? FieldType::Integer : FieldType::Decimal), $number, $value);
    }

    /** Makes a true/false cell from `true`, `false`, `1`, `0` and their text forms. Anything else stays as text. */
    private static function boolean(mixed $value): self
    {
        $flag = match (true) {
            is_bool($value) => $value,
            in_array($value, [1, '1', 'true'], true) => true,
            in_array($value, [0, '0', 'false'], true) => false,
            default => null,
        };

        return $flag === null ? new self(FieldType::String, (string) $value) : new self(FieldType::Boolean, $flag);
    }

    /** Makes a date or time cell and converts it to the app timezone. Text that is not a date stays as text. */
    private static function temporal(FieldType $type, mixed $value): self
    {
        try {
            $moment = $value instanceof CarbonInterface
                ? $value
                : CarbonImmutable::parse((string) $value)->setTimezone((string) config('app.timezone'));
        } catch (Throwable) {
            return new self(FieldType::String, (string) $value);
        }

        return new self($type, $moment);
    }

    /** Counts the significant digits of a number (no sign, no dot, no leading zeros). */
    private static function digits(string $number): int
    {
        return strlen(ltrim(str_replace(['-', '+', '.'], '', $number), '0'));
    }
}
