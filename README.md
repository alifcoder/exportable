# alifcoder/export-sdk

Async document export (csv, xlsx) for Laravel, built on `alifcoder/query-filter` (`^2.0.1`, enforced by Composer).

The package is host-driven and **stateless**: it ships **no routes, no controllers, no database table, no schedule, no config defaults and no file storage**. The host provides the configuration, the endpoints, the permission check, the place files are kept and the list of finished exports.

## Flow
The host's endpoint calls `ExportServiceInterface::create($owner, $dto)`: it takes a quota slot (cache), applies the host filter, counts the output rows once (cap and progress total) and queues `RunExport` with an `ExportTask` (id, owner id, locale, request, row total). The host authorises the owner before calling (`definition()` and `create()` check nothing but the key). The job passes through the host's `queue.middleware`, which signs the owner in (so the host filter's `before()` scope applies) and re-checks the permission, then streams rows with `lazy()`, flattens children, writes a **local temporary file**, hands it to the host's `ExportFileStore` (e.g. as an attachment), removes the temporary file, frees the quota slot and dispatches `ExportFinished` (completed or failed, with an error code). While it runs, `ExportProgressed` events tell the owner's client how far it is. Nothing is persisted by the package; the host keeps whatever it needs from `ExportFinished`.

## Install
1. `composer require alifcoder/export-sdk`
2. `php artisan vendor:publish --tag=export-config`, set **every** key in `config/export.php` (a missing one fails with `Missing export configuration "export.<key>"`).
3. Bind `Contracts\ExportFileStore`: `put(ExportTask, localPath, fileName, mime): string` (returns your file id). Links, listing and deletion are the host's. There is no auth contract and no gate/ability/guard setting.
4. Write your own endpoints over `Services\Interfaces\ExportServiceInterface` (`definition($key)`, `create($owner, $dto)`), authorise the user there, and listen to `ExportFinished`.
5. List job middleware classes in `queue.middleware` (resolved from the container, required key, `[]` for none). A middleware receives the `RunExport` job (`$job->task` is `ExportTask::toArray()`), signs the owner in, re-checks the permission and calls `$next($job)`. An `ExportException` it throws fails the export with that error code:

   ```php
   final class ExportAsOwner
   {
       public function handle(RunExport $job, Closure $next): mixed
       {
           $owner = User::find($job->task['owner_id']) ?? throw ExportException::ownerMissing();

           if (! Gate::forUser($owner)->allows('viewAny', ...)) {
               throw ExportException::forbidden();
           }

           Auth::shouldUse('api');
           Auth::guard('api')->setUser($owner); // restore the previous user/guard afterwards

           return $next($job);
       }
   }
   ```
6. Run a queue worker for `queue.name` with enough memory/timeout for xlsx. The queue connection's `retry_after` must exceed `queue.timeout`, otherwise a second worker picks up the running job. The quota needs a cache store with atomic locks. Discovered `ResourceExportable` columns are cached for 10 minutes in the default cache store.
7. Authorize the private channel `App.Models.User.{id}` (Laravel's default user channel) if you use progress events.

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
| `progress.{enabled,min_rows,step_percent}` | broadcast `ExportProgressed` for exports of at least `min_rows` rows, at most every `step_percent` points, and a final 100 once the last row is written (the finished event then announces the file) |
| `style.{date_format,datetime_format}` | PHP `date()` formats; `Column::date()` uses `date_format` |
| `style.csv.{delimiter,enclosure,line_ending,bom}` | csv field separator and quote (single byte), row ending, UTF-8 byte order mark |
| `style.xlsx.font`, `.header`, `.number_format`, `.integer_format`, `.column_width` | font, header look (ARGB colours), Excel number formats, width = heading length + padding clamped to min..max |

## Events
- `ExportFinished` (`task`, `fileId`, `fileName`, `rows`, `errorCode`; `succeeded()` is `errorCode === null`) when an export is written or has failed. Switch off with `events.finished`.
- `ExportProgressed` (`ShouldBroadcastNow`, private channel `App.Models.User.{ownerId}`, `export.progressed`): `id`, `percent`, `rows`, `total_rows`.

## Layout and safety
- Children are flattened: one row per child with document columns repeated; a document without children yields one row with blank child cells. Output order: `columns` then `child_columns`.
- Caps count output rows. csv: formula-prefix guard on non-numeric columns; layout (delimiter, enclosure, line ending, BOM) from `style.csv`. xlsx: non-numeric cells are inert strings.
- bool is `1`/`0`; override per column with a closure.
- Lazy loading is blocked while exporting: columns declare the relations they need (`Column::make(...)->relations('customer')`; constrained loads such as `['lines' => fn ($q) => ...]` are accepted); `ResourceExportable` takes them from the host's `$with` list.

## Performance and sizing
Measured earlier (2 child lines per document, Postgres 16): csv 500k rows in 54s at 48MB peak, xlsx 500k rows in 55s at 48MB peak. Index the child foreign key.

## Development
```
composer check      # pint --test, phpstan (level 5), phpunit
vendor/bin/pint     # fix style
```
The tests configure the owner middleware and bind the file store themselves (`tests/Fixtures`), exactly as a host would.
