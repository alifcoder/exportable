<?php

declare(strict_types=1);

return [
    'no_data' => 'There is no data to export.',
    'too_many_rows' => 'Too many rows for :format export (maximum :max).',
    'unknown_column' => 'Unknown column ":column".',
    'invalid_request' => 'The export request is invalid.',
    'nested_too_deep' => 'Lists can be nested :max levels deep at most.',
    'errors' => [
        'invalid_request' => 'The export request is invalid.',
        'nested_too_deep' => 'Lists are nested too deeply.',
        'unknown_column' => 'Some requested columns do not exist.',
        'no_data' => 'There is no data to export.',
        'too_many_rows' => 'There are too many rows for this export format.',
        'unpageable_query' => 'The export query cannot be read in chunks.',
        'unsupported_eager_load' => 'The export query has an unsupported eager load.',
        'resource_failed' => 'A row could not be rendered for the export.',
        'row_limit_exceeded' => 'The export exceeded the row limit.',
        'storage_write_failed' => 'The export file could not be stored.',
        'invalid_registration' => 'The export is set up wrongly.',
        'invalid_configuration' => 'The export settings are incomplete.',
        'export_failed' => 'The export could not be completed.',
    ],
];
