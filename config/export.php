<?php

declare(strict_types=1);

/*
 * The package merges no defaults: every key below is required and a missing one fails with
 * "Missing export configuration". Publish this file and set the values.
 */
return [
    // Hours a finished export stays downloadable.
    'ttl_hours' => 3,

    // Documents loaded per query while writing.
    'chunk_size' => 500,

    // Output-row caps per format.
    'max_rows' => [
        'csv' => 500_000,
        'xlsx' => 500_000,
    ],

    // Exports one owner may have pending or processing at once.
    'max_active_per_user' => 3,

    'queue' => [
        'connection' => null,
        'name' => 'exports',
        // Worker retry_after must exceed this value (seconds).
        'timeout' => 1800,
    ],

    // An active-export slot that is never released (worker killed) expires queue.timeout + this margin
    // (seconds) after it was taken.
    'stale_margin_seconds' => 600,

    // Dispatch Events\ExportFinished when an export becomes completed or failed (file ready notification).
    'events' => [
        'finished' => true,
    ],

    // Progress events (Events\ExportProgressed) for the owner's client.
    'progress' => [
        'enabled' => true,
        // Only exports with at least this many output rows report progress.
        'min_rows' => 1000,
        // Minimum percentage points between two events.
        'step_percent' => 5,
    ],

    'style' => [
        // PHP date() formats of date values (Column::date() uses date_format, other dates datetime_format).
        'date_format' => 'Y-m-d',
        'datetime_format' => 'Y-m-d H:i:s',

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
            // Column width in characters: heading length + padding, clamped to min..max.
            'column_width' => ['min' => 10, 'max' => 60, 'padding' => 4],
        ],
    ],
];
