# alifcoder/export-sdk

Async document export (csv, xlsx) for Laravel, built on `alifcoder/query-filter` (`^2.0.1`, enforced by Composer).

The package is host-driven and **stateless**: it ships **no routes, no controllers, no database table, no schedule, no config defaults and no file storage**. The host provides the configuration, the endpoints, the permission check, the place files are kept and the list of finished exports.

## Flow
The host's endpoint calls `ExportServiceInterface::create($owner, $dto)`: it checks the key and the owner's permission (`ExportAuth::allows`), takes a quota slot (cache), applies the host filter, counts the output rows once (cap and progress total) and queues `RunExport` with an `ExportTask` (id, owner id, locale, request, row total). The job runs as the owner (so the host filter's `before()` scope applies), streams rows with `lazy()`, flattens children, writes a **local temporary file**, hands it to the host's `ExportFileStore` (e.g. as an attachment), removes the temporary file, frees the quota slot and dispatches `ExportFinished` (completed or failed, with an error code). While it runs, `ExportProgressed` events tell the owner's client how far it is. Nothing is persisted by the package; the host keeps whatever it needs from `ExportFinished`.

## Install
1. `composer require alifcoder/export-sdk`
2. `php artisan vendor:publish --tag=export-config`, set **every** key in `config/export.php` (a missing one fails with `Missing export configuration "export.<key>"`).
3. Bind the two host contracts:
   - `Contracts\ExportAuth`: `allows($user, $key)` and `actingAs($ownerId, Closure)`. The check lives in the host's implementation; there is no gate/ability/guard setting.
   - `Contracts\ExportFileStore`: `put(ExportTask, localPath, fileName, mime): string` (returns your file id). Links, listing and deletion are the host's.
4. Write your own endpoints over `Services\Interfaces\ExportServiceInterface` (`definition`, `create`) and listen to `ExportFinished`.
5. Run a queue worker for `queue.name` with enough memory/timeout for xlsx. The queue connection's `retry_after` must exceed `queue.timeout`, otherwise a second worker picks up the running job. The quota needs a cache store with atomic locks. Discovered `ResourceExportable` columns are cached for 10 minutes in the default cache store.
6. Authorize the private channel `exports.{ownerId}` if you use progress events.

## Documents: extend the registry
Nothing is declared per document. Bind a subclass of `Helpers\ExportRegistry` and override `keys()`, `has($key)` and `get($key): Exportable`; return a `Helpers\ResourceExportable` built from a model, its API resource, a filter closure and a base-query closure (both receive the request's `data` parameters; the base query is where the host's own list defaults belong) and the columns are discovered from the resource output (`business_partner.name`), the children from its HasMany list (`products`). Hand-written `Contracts\Exportable` classes still work (`register`, `registerUsing`); they implement `query(array $parameters)` for the base query.

```php
$this->app->singleton(ExportRegistry::class, MyRegistry::class);
```

## Request
```json
{ "exportable": "sale.sale",
  "data": { "filter": {}, "sort": "-document_date", "search": "abc" },
  "file": { "format": "xlsx", "columns": ["number", "total_sum"],
            "include_children": true, "child_columns": ["quantity", "price"], "title": "Sales" } }
```
`data` is passed **whole** to the filter (`filter(array $parameters)`), which validates it. The host must strip what a client may never send (e.g. a POS auth id).

Errors raised by the service: `ValidationException` (filter / row cap), `ExportException` `forbidden` (unknown key or no permission, 403) and `too_many_active` (429).

## Config (all required)
| Key | Meaning |
|---|---|
| `ttl_hours` | hours a finished export stays downloadable |
| `chunk_size`, `max_rows.{csv,xlsx}`, `max_active_per_user` | paging, caps, quota |
| `queue.{connection,name,timeout}` | `connection`/`name` may be `null` but must be present |
| `stale_margin_seconds` | a quota slot never released (worker killed) expires `queue.timeout` + this many seconds after it was taken |
| `events.finished` | dispatch `ExportFinished` when an export completes or fails; `false` silences it |
| `progress.{enabled,min_rows,step_percent}` | broadcast `ExportProgressed` for exports of at least `min_rows` rows, at most every `step_percent` points; never sends 100 (the finished event does) |
| `style.{date_format,datetime_format}` | PHP `date()` formats; `Column::date()` uses `date_format` |
| `style.xlsx.font`, `.header`, `.number_format`, `.integer_format`, `.column_width` | font, header look (ARGB colours), Excel number formats, width = heading length + padding clamped to min..max |

## Events
- `ExportFinished` (`task`, `fileId`, `fileName`, `rows`, `errorCode`; `succeeded()` is `errorCode === null`) when an export is written or has failed. Switch off with `events.finished`.
- `ExportProgressed` (`ShouldBroadcastNow`, private channel `exports.{ownerId}`, `export.progressed`): `id`, `percent`, `rows`, `total_rows`.

## Layout and safety
- Children are flattened: one row per child with document columns repeated; a document without children yields one row with blank child cells. Output order: `columns` then `child_columns`.
- Caps count output rows. csv: formula-prefix guard on non-numeric columns, UTF-8 BOM. xlsx: non-numeric cells are inert strings.
- bool is `1`/`0`; override per column with a closure.
- Lazy loading is blocked while exporting: columns declare the relations they need (`Column::make(...)->relations('customer')`; constrained loads such as `['lines' => fn ($q) => ...]` are accepted); `ResourceExportable` takes them from the host's `$with` list.

## Performance and sizing
Measured earlier (2 child lines per document, Postgres 16): csv 500k rows in 54s at 48MB peak, xlsx 500k rows in 55s at 48MB peak. Index the child foreign key.

## Development
```
composer check      # pint --test, phpstan (level 5), phpunit
vendor/bin/pint     # fix style
```
The tests bind the auth and the file store themselves (`tests/Fixtures`), exactly as a host would.
