<?php

declare(strict_types=1);

return [
    'no_data' => 'There is no data to export.',
    'too_many_rows' => 'Too many rows for :format export (maximum :max).',
    'unknown_column' => 'Unknown column ":column".',
    'column_not_offered' => 'Column ":column" is not offered by the filter.',
    'needs_document_column' => 'At least one column must come from the document itself, not from a list.',
    'nested_too_deep' => 'Lists can be nested :max levels deep at most.',
    'errors' => [
        'row_limit_exceeded' => 'The export exceeded the row limit.',
        'storage_write_failed' => 'The export file could not be stored.',
        'invalid_column_value' => 'A column value could not be written to the file.',
        'invalid_registration' => 'The export is set up wrongly.',
        'invalid_configuration' => 'The export settings are incomplete.',
        'export_failed' => 'The export could not be completed.',
    ],
];
