<?php

declare(strict_types=1);

/*
 * The package merges no defaults: every key below is required and a missing one fails with
 * "Missing export configuration". Publish this file and set the values.
 */
return [
    // Documents loaded per query while writing.
    'chunk_size' => 500,

    // Output-row caps per format.
    'max_rows' => [
        'csv' => 500_000,
        'xlsx' => 500_000,
    ],

    'queue' => [
        'connection' => null,
        'name' => 'exports',
        // Worker retry_after must exceed this value (seconds).
        'timeout' => 1800,
    ],

    // The "finished" announcement of an export is remembered queue.timeout + this margin (seconds), so it is
    // sent once even if both the job and the queue's failure hook reach it.
    'stale_margin_seconds' => 600,

    // Dispatch Events\ExportFinished when an export becomes completed or failed (file ready notification).
    'events' => [
        'finished' => true,
    ],

    // Progress events (Events\ExportProgressed) for the client.
    'progress' => [
        'enabled' => true,
        // Only exports with at least this many output rows report progress.
        'min_rows' => 1000,
        // Minimum percentage points between two events; 1 streams every percent (10 => 11 => ... => 33).
        'step_percent' => 1,
    ],

    'style' => [
        // PHP date() formats of dates in csv (xlsx dates are real Excel dates, formatted by style.xlsx below).
        'date_format' => 'Y-m-d',
        'datetime_format' => 'Y-m-d H:i:s',
        'time_format' => 'H:i:s',

        // Text of boolean cells (csv and xlsx); a translation key is translated.
        'boolean' => ['true' => 'Yes', 'false' => 'No'],

        'csv' => [
            // Single-byte characters.
            'delimiter' => ',',
            'enclosure' => '"',
            'line_ending' => "\n",
            // UTF-8 byte order mark; Excel needs it to open UTF-8 csv correctly.
            'bom' => true,
        ],

        'xlsx' => [
            'font' => ['name' => 'Calibri', 'size' => 11],
            'header' => [
                'bold' => true,
                'font_size' => 11,
                // ARGB hex.
                'font_color' => 'FF000000',
                'background' => 'FFE8EEF7',
            ],
            // Excel number formats of numeric cells.
            'number_format' => '#,##0.00',
            'integer_format' => '#,##0',
            // Excel formats of date cells (Excel syntax, not PHP date()): real dates, sortable and filterable.
            'date_format' => 'yyyy-mm-dd',
            'datetime_format' => 'yyyy-mm-dd hh:mm:ss',
            'time_format' => 'hh:mm:ss',
            // Column width in characters: the longest heading or value of the column (sub rows included) + padding, clamped to min..max.
            'column_width' => ['min' => 10, 'max' => 60, 'padding' => 4],
        ],
    ],
];
