<?php

declare(strict_types=1);

return [
    // Documents available for export: key => class implementing Contracts\Exportable. Hosts may also call
    // app(ExportRegistry::class)->register($key, $class) from a service provider.
    'exportables' => [],

    // Storage disk for generated files; null uses filesystems.default.
    'disk' => null,
    'directory' => 'exports',
    'ttl_hours' => 24,
    'chunk_size' => 500,

    // Output-row caps per format.
    'max_rows' => [
        'csv' => 500_000,
        'xlsx' => 500_000,
    ],
    'max_active_per_user' => 3,

    'queue' => [
        'connection' => null,
        'name' => 'exports',
        // Worker retry_after must exceed this value.
        'timeout' => 1800,
    ],
    // A processing row whose started_at is older than queue.timeout + this margin is treated as stuck.
    'stale_margin_seconds' => 600,
    // A pending row created longer ago than this many hours is treated as stuck (dispatch lost).
    'stale_pending_hours' => 24,

    // Auth guard used to run the job as the owner; null uses the default guard.
    'guard' => null,
    // Gate ability checked as Gate::forUser($user)->allows($ability, [$exportableKey]).
    'ability' => 'data-export',

    'table' => 'data_exports',
    // uuid or int.
    'owner_key_type' => 'uuid',

    // Only these keys of the request "data" object reach the host filter.
    'data_parameters' => ['filter', 'where', 'search', 'search_type', 'sort', 'with_deleted', 'only_deleted'],

    'routes' => [
        'enabled' => true,
        'prefix' => 'exports',
        'middleware' => ['api', 'auth'],
    ],

    'prune' => [
        'schedule' => true,
    ],
];
