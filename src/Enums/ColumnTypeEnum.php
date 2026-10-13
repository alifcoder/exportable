<?php

declare(strict_types=1);

namespace Alif\Export\Enums;

/**
 * How a column's cells are written. A column without a type is read from each value: int, float, bool and
 * date objects keep their own type, everything else is text.
 */
enum ColumnTypeEnum: string
{
    case String = 'string';
    /** A number, whole or decimal by the value of each cell. */
    case Number = 'number';
    case Integer = 'integer';
    case Decimal = 'decimal';
    /** A number shown with a trailing percent sign: 25 is written as 25%, it is not scaled. */
    case Percent = 'percent';
    case Currency = 'currency';
    case Boolean = 'boolean';
    case Date = 'date';
    case DateTime = 'datetime';
    case Time = 'time';

    /** Keys ending like this are numbers when their value is a numeric string (decimals come as strings). */
    private const string NUMERIC_KEY = '/(sum|price|quantity|amount|total|rate|cost|balance|discount)$/';

    /** A date-time as a resource renders it (`2026-03-04T05:06:07Z`), and a date alone. */
    private const string DATETIME_VALUE = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/';

    private const string DATE_VALUE = '/^\d{4}-\d{2}-\d{2}$/';

    /**
     * The type of a column read from one value of the resource's output. A resource renders through JSON, so a date
     * arrives as text and a decimal as a numeric string: those are recognised by their shape (a decimal also by its
     * key), the rest by the value's own type. Null when nothing is recognised: the column is then read cell by cell.
     */
    public static function guess(string $key, mixed $value): ?self
    {
        return match (true) {
            is_bool($value) => self::Boolean,
            is_int($value), is_float($value) => self::Number,
            ! is_string($value) => null,
            preg_match(self::DATETIME_VALUE, $value) === 1 => self::DateTime,
            preg_match(self::DATE_VALUE, $value) === 1 => self::Date,
            is_numeric($value) && preg_match(self::NUMERIC_KEY, $key) === 1 => self::Number,
            default => null,
        };
    }

    /** True for the types that are written as numbers. */
    public function isNumeric(): bool
    {
        return match ($this) {
            self::Number, self::Integer, self::Decimal, self::Percent, self::Currency => true,
            default => false,
        };
    }

    /** True for the types that are written as dates or times. */
    public function isTemporal(): bool
    {
        return in_array($this, [self::Date, self::DateTime, self::Time], true);
    }
}
