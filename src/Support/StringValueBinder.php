<?php

declare(strict_types=1);

namespace Alif\Export\Support;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/** Writes numbers as numbers and everything else as inert strings (never formulas). */
final class StringValueBinder extends DefaultValueBinder
{
    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_NUMERIC);

            return true;
        }

        $cell->setValueExplicit($value === null ? '' : (string) $value, DataType::TYPE_STRING);

        return true;
    }
}
