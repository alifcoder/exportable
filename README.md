# alifcoder/export-sdk

Async document export (csv, xlsx, pdf) for Laravel, built on `alifcoder/query-filter` (`>=2.0.1 <3.0.0`).

## Flow
`POST {prefix}` validates against a server-side whitelist, stores a `data_exports` row and queues `RunExport`. The job runs as the owner (so the host filter's `before()` scope applies), streams rows with `lazy()`, flattens children and writes the file to the configured disk. Clients poll `GET {prefix}/{id}` and download from `GET {prefix}/{id}/download`. Files expire after `ttl_hours` and are pruned hourly.

## Install
1. `composer require alifcoder/export-sdk`
2. `php artisan vendor:publish --tag=export-config --tag=export-migrations`, then migrate.
3. Set `routes.prefix`, `routes.middleware`, `guard`, `owner_key_type`, `queue.name` in `config/export.php`.
4. Define the Gate ability (`export.ability`, default `data-export`): `Gate::define('data-export', fn ($user, string $key) => ...)`, or bind your own `Alif\Export\Contracts\ExportAuth`.
5. Run a queue worker for the configured queue with enough memory/timeout for xlsx and pdf. The worker/connection `retry_after` must exceed `export.queue.timeout`, otherwise a slow export is redelivered while still running. Rows stuck in `processing` (started more than `queue.timeout + stale_margin_seconds` ago) or `pending` (created more than `stale_pending_hours` ago, default 24) stop counting toward the active quota and are pruned with their files; failed rows are pruned after `ttl_hours`.

## Host contracts
- `Contracts\Exportable`: one class per document (title, columns, optional hasMany child relation and child columns, base query, `filter(array $params): EBFilterInterface`). Register in a service provider: `app(ExportRegistry::class)->register('sale.sales', SaleExportable::class)`.
- `Contracts\ExportAuth`: `allows($user, $key)` and `actingAs($ownerId, Closure)`. Default `LaravelExportAuth` uses the Gate and the guard's user provider.
- Columns declare the relations they need (`Column::make('Customer', fn ($row) => $row->customer->name)->relations('customer')`). Undeclared relations fail the job (lazy loading is prevented during export).

## Request
```json
{ "exportable": "sale.sales",
  "data": { "filter": {}, "sort": "-date", "search": "abc" },
  "file": { "format": "xlsx", "columns": ["number", "total"],
            "include_children": true, "child_columns": ["sku", "qty"], "title": "Sales" } }
```
Response `202 {"data": {id, exportable, format, status, rows_count, error_code, created_at, finished_at, expires_at, download_url}}`.
`GET {prefix}/exportables/{key}` returns the definition (formats with row caps, columns). Errors: 422 validation / row cap, 403 permission, 404 not owner, 409 not ready, 410 expired, 429 too many active exports.

Only `export.data_parameters` keys of `data` reach the filter; top-level `export.prohibited_parameters` (`all`, `pos_auth_id`) are rejected.

## Layout and safety
- Children are flattened: one row per child with document columns repeated; a document without children yields one row with blank child cells. Output order: `columns` then `child_columns`.
- Caps count output rows (csv 500k, xlsx 50k, pdf 2k).
- csv: formula-prefix guard on non-numeric columns, UTF-8 BOM. xlsx: non-numeric cells are inert strings. pdf: escaped Blade output, no remote/PHP/JS.
- bool is `1`/`0`; dates are `Y-m-d H:i:s`; override per column with a closure.

## Version gate
`Support\QueryFilterCompatibility` (`MIN`, `MAX_EXCLUSIVE`) is checked lazily at export entry points (controller actions and `RunExport`), never at boot. Keep it in sync with the `composer.json` constraint.
