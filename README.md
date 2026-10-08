# alifcoder/export-sdk

Async document export (csv, xlsx) for Laravel, built on `alifcoder/query-filter` (`^2.0.1`, enforced by Composer).

## Flow
`POST {prefix}` validates against a server-side whitelist, stores a `data_exports` row and queues `RunExport`. The job runs as the owner (so the host filter's `before()` scope applies), streams rows with `lazy()`, flattens children and writes the file to the configured disk. Clients poll `GET {prefix}/{id}` and download from `GET {prefix}/{id}/download`. Files expire after `ttl_hours` and are pruned hourly.

## Install
1. `composer require alifcoder/export-sdk`
2. `php artisan vendor:publish --tag=export-config --tag=export-migrations`, then migrate.
3. Set `routes.prefix`, `routes.middleware`, `guard`, `owner_key_type`, `queue.name` in `config/export.php`. The queue must be consumed by a worker (Horizon supervisor, `queue:work`). `StartExport` needs a cache store with atomic locks (redis, database, file, memcached, array).
4. Define the Gate ability (`export.ability`, default `data-export`): `Gate::define('data-export', fn ($user, string $key) => ...)`, or bind your own `Alif\Export\Contracts\ExportAuth`.
5. Run a queue worker for the configured queue with enough memory/timeout for xlsx. The worker/connection `retry_after` must exceed `export.queue.timeout`, otherwise a slow export is redelivered while still running. Rows stuck in `processing` (started more than `queue.timeout + stale_margin_seconds` ago) or `pending` (created more than `stale_pending_hours` ago, default 24) stop counting toward the active quota and are pruned with their files; failed rows are pruned after `ttl_hours`.

6. Host gotchas found integrating with a real app:
   - `FormRequest::failOnUnknownFields()` is supported: `ExportCreateRequest` opts out because `data` is validated by the host filter.
   - Set `routes.prefix` to where your API lives (e.g. `api/v1/exports`) and `routes.middleware` to your API auth; `guard` must be the guard whose provider resolves the owner in the queue job.

## Layers
`Http/Controllers` + `Http/Requests/Export` (validate, `getDto()`) → `Services/ExportService` (`Services/Interfaces/ExportServiceInterface`) → invokable `Services/Actions/Export/{Start,Retry,Generate,Claim,Complete,Fail,Delete}Export` → `Entities/DataExport` → `Transformers/Export`. Typed data travels in `DTO/Export/{ExportCreateDTO,ExportListDTO}`; failed invariants are `Exceptions/ExportException`; `Helpers/` holds `Column`, `ExportRegistry`, `ExportPlan`, `ExportBuilder`, `ExportWriter`, `ExportFile` and the csv/xlsx writers. `Entities/DataExport` only holds the guard, casts (`Enums/ExportStatus`) and query scopes.

## Host contracts
- `Contracts\Exportable`: one class per document (title, columns, optional hasMany child relation and child columns, base query, `filter(array $params): EBFilterInterface`). Register them in `config/export.php` (`'exportables' => ['sale.sales' => SaleExportable::class]`) or from a service provider: `app(ExportRegistry::class)->register('sale.sales', SaleExportable::class)`.
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
`GET {prefix}/definition?exportable={key}` returns the definition (formats with row caps, columns). Errors: 422 validation / row cap, 403 permission (also `definition` for an unknown key, so key existence is not revealed), 404 not owner / unknown export, 409 not ready, 410 expired, 429 too many active exports.

### Managing exports
- `GET {prefix}?status=&exportable=&per_page=` lists the caller's exports, newest first (simple pagination, `per_page` 1-100, default 20).
- `DELETE {prefix}/{id}` cancels a pending export or deletes a finished/failed one with its file (204). A live `processing` export is 409; a stuck one can be deleted.
- `POST {prefix}/{id}/retry` starts a new export with the options of a failed one (202). 409 if not failed, 422 if the definition no longer has the chosen columns, 403 if the permission is gone, 429 over quota.
- `download` re-checks the Gate on every call: an owner who lost the permission gets 403 even if the file still exists.

### Notifications
`Alif\Export\Events\ExportFinished` (carries the `DataExport`) is dispatched when an export becomes `completed` or `failed`. Listen to it in the host to notify the owner (mail, push, broadcast).

Only `export.data_parameters` keys of `data` reach the filter; everything else is dropped.

## Layout and safety
- Children are flattened: one row per child with document columns repeated; a document without children yields one row with blank child cells. Output order: `columns` then `child_columns`.
- Caps count output rows (csv 500k, xlsx 500k).
- csv: formula-prefix guard on non-numeric columns, UTF-8 BOM. xlsx: non-numeric cells are inert strings.
- bool is `1`/`0`; dates are `Y-m-d H:i:s`; override per column with a closure.

## Host example
```php
final class SaleExportable implements Exportable
{
    public function title(): string { return 'Sales'; }
    public function columns(): array
    {
        return [
            'number' => Column::make('Number'),
            'total' => Column::make('Total')->numeric(),
            'customer' => Column::make('Customer', fn ($s) => $s->customer->name)->relations('customer'),
        ];
    }
    public function childRelation(): ?string { return 'lines'; }   // must be HasMany
    public function childColumns(): array { return ['sku' => Column::make('SKU'), 'qty' => Column::make('Qty')->numeric()]; }
    public function query(): Builder { return Sale::query(); }
    public function filter(array $parameters): EBFilterInterface { return new SaleFilter($parameters); }
}

// AppServiceProvider::boot()
app(ExportRegistry::class)->register('sale.sales', SaleExportable::class);
Gate::define('data-export', fn ($user, string $key) => $user->can("export.$key"));
```

## Performance and sizing
Measured with `tests/Stress` (2 child lines per document, Postgres 16): csv 500k rows in 54s at 48MB peak, xlsx 500k rows in 55s at 48MB peak (rows are streamed to disk by OpenSpout). csv and xlsx memory stays flat.
- **Index the child foreign key** (`order_lines.order_id`). The submit-time row count and the eager load both filter on it; without the index a 25k-document export took 160s instead of 2s.
- Re-run on your own data and infrastructure: `STRESS_ROWS=250000 STRESS_MEMORY_MB=512 vendor/bin/phpunit --group stress`.

## Deployment
- Use a disk shared by web and worker servers (s3, nfs) for `export.disk`; a `local` disk breaks downloads when they run on different machines.
- Run the scheduler (`schedule:run`) in the host: expired files are pruned by an hourly `model:prune`.

## Development
This is a package, not an app: there is no `artisan`. Use:
```
composer check      # pint --test, phpstan (level 5), phpunit
composer test
vendor/bin/pint     # fix style
```
Row caps count flattened output rows, checked at submit (422) and again while streaming.

Opt-in suites (excluded from `composer test`):
```
# Postgres instead of sqlite (any suite): TEST_DB_HOST=127.0.0.1 TEST_DB_PORT=5432 TEST_DB_USERNAME=... TEST_DB_PASSWORD=... TEST_DB_DATABASE=...
# Real S3 + real Redis queue (needs an S3-compatible endpoint on :59090 with bucket export-test, Redis on :56379):
TEST_INFRA=1 TEST_DB_HOST=... vendor/bin/phpunit --group infra
# Scale / memory:
STRESS_ROWS=25000 STRESS_CSV_ROWS=250000 vendor/bin/phpunit --group stress
```

## Troubleshooting
- Export stays `pending`: no worker on the `export.queue.name` queue.
- Export redelivered while running: worker `retry_after` must exceed `export.queue.timeout`.
- `failed` with `export_failed`: check logs; an undeclared relation in a column (lazy loading is blocked) is the usual cause.
- `failed` with `storage_write_failed`: the disk refused the file (permissions, full disk, bad S3 credentials); check the disk's `throw` option and logs.
