<?php

namespace App\Support\Spreadsheet;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * Value binder for the Excel exports (ventes, achats, clients, stock…).
 *
 * The default binder turns any text starting with "=" into a formula, so a
 * client or a description typed as `=HYPERLINK("http://…")` would run as a
 * formula when the export is opened (CSV / formula injection). The exports
 * never write formulas on purpose, so such text is always kept as plain text.
 */
class TextSafeValueBinder extends DefaultValueBinder
{
    public static function dataTypeForValue(mixed $value): string
    {
        if (is_string($value) && strlen($value) > 1 && $value[0] === '=') {
            return DataType::TYPE_STRING;
        }

        return parent::dataTypeForValue($value);
    }
}
