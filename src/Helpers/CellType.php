<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Alif\QueryFilter\Enums\FieldType;

/** What the export decides about a {@see FieldType}: which are numbers or moments, and the type a plain value suggests. */
final class CellType
{
    /** Keys ending like this are numbers when their value is a numeric string (decimals come as strings). */
    private const string NUMERIC_KEY = '/(sum|price|quantity|amount|total|rate|cost|balance|discount)$/';

    /** A date-time as a resource renders it (`2026-03-04T05:06:07Z`), and a date alone. */
    private const string DATETIME_VALUE = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/';

    private const string DATE_VALUE = '/^\d{4}-\d{2}-\d{2}$/';

    /**
     * The type of a column read from one value of the resource's output. A resource renders through JSON, so a date
     * arrives as text and a decimal as a numeric string: those are recognised by their shape (a decimal also by its
     * key), the rest by the value's own type. Null when nothing is recognised: the column is then read cell by cell.
     * Time and Uuid come only from a `Field`.
     */
    public static function guess(string $key, mixed $value): ?FieldType
    {
        return match (true) {
            is_bool($value) => FieldType::Boolean,
            is_int($value) => FieldType::Integer,
            is_float($value) => FieldType::Decimal,
            ! is_string($value) => null,
            preg_match(self::DATETIME_VALUE, $value) === 1 => FieldType::DateTime,
            preg_match(self::DATE_VALUE, $value) === 1 => FieldType::Date,
            is_numeric($value) && preg_match(self::NUMERIC_KEY, $key) === 1 => FieldType::Decimal,
            default => null,
        };
    }

    /** True for the types written as numbers. */
    public static function isNumeric(FieldType $type): bool
    {
        return $type === FieldType::Integer || $type === FieldType::Decimal;
    }

    /** True for the types written as dates or times. */
    public static function isTemporal(FieldType $type): bool
    {
        return in_array($type, [FieldType::Date, FieldType::DateTime, FieldType::Time], true);
    }
}
