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
