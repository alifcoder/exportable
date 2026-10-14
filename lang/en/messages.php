<?php

declare(strict_types=1);

return [
    'no_data' => 'There is no data to export.',
    'too_many_rows' => 'Too many rows for :format export (maximum :max).',
    'unknown_column' => 'Unknown column ":column".',
    'invalid_request' => 'The export request is invalid.',
    'nested_too_deep' => 'Lists can be nested :max levels deep at most.',
    'unknown_relation' => 'The relation ":relation" does not exist.',
    'unpageable_query' => 'The export query cannot be paged: :reason.',
    'unsupported_eager_load' => 'The eager load ":relation" is constrained; the export can only repeat plain eager loads.',
    'resource_failed' => 'The resource ":resource" failed to render a row: :reason',
    'row_limit_exceeded' => 'Export exceeds the :max row limit.',
    'storage_write_failed' => 'Could not store the export file: :reason.',
    'invalid_configuration' => 'Missing export configuration "export.:key".',
    'invalid_registration_detail' => 'The export is set up wrongly: :reason',
    'registration' => [
        'no_model' => 'The queued export has no "model"; it was queued by an older version.',
        'unsupported_driver' => 'Export cannot page the ":driver" driver.',
    ],
    'unpageable' => [
        'group_or_union' => 'it groups or unions rows',
        'limit_or_offset' => 'it has a limit or offset',
        'distinct' => 'it selects distinct rows',
    ],
    'storage' => [
        'temp_file' => 'cannot create a temporary file',
        'cannot_open' => 'cannot open :path',
        'xlsx_reopen' => 'cannot reopen the xlsx to finish it',
        'xlsx_save' => 'cannot save the finished xlsx',
        'xlsx_read' => 'cannot read the xlsx sheet',
    ],
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
