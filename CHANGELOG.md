# Changelog

## 0.3.3

- `create()` refuses an export that selects nothing with a `data` validation error (422) before a job is queued; an empty file is never produced.
- Fixed: the row count (progress total, row cap) honours the filter's own window (limit, page); it used to count every row.

## 0.3.2

- Progress ends with a final `100` event once the last row is written (it used to stop below 100).

## 0.3.1

- Fixed: a child table without its key column (a pivot-like table) is no longer ordered by it, so documents with such children export instead of failing.

## 0.3.0

Breaking: the package is host-driven and stateless.

- No database table or migration, no HTTP layer (controller, requests, resources), no routes, schedule, pruning, storage disk or config defaults. A missing `export.*` key fails with `invalid_configuration`. The host owns its endpoints, where files are kept (`Contracts\ExportFileStore`, e.g. attachments) and the list of finished exports.
- `Contracts\ExportAuth` is removed: authorisation is the host's. `definition(string $key)` takes no user and `create()` checks no permission. Running as the owner and re-checking the permission at run time is job middleware listed in the new required key `queue.middleware`; an `ExportException` thrown there fails the export with its code.
- Removed config keys: `guard`, `ability`, `table`, `owner_key_type`, `routes`, `prune`, `exportables`, `data_parameters`, `disk`, `directory`, `stale_pending_hours`.
- `DTO\Export\ExportTask` is the job payload; `RunExport` takes it as an array. `ExportServiceInterface` is `definition()` and `create()` (returns the task; checks the key, the quota, the filter and the row cap). `ExportFileStore` is only `put(ExportTask, ...)`.
- `Contracts\Exportable::query(array $parameters)` replaces `query()`: the host builds the document's base query (default scopes, aggregates, joins) so the file matches its list. `ResourceExportable` takes it as a closure after the filter closure.
- Quota: one cache slot per export (`export-slot:{owner}:{task}`), expiring `queue.timeout + stale_margin_seconds`. The owner lock is held only while counting and adding a slot; releasing deletes the slot (idempotent, lock-free, safe with a synchronous queue).
- `ExportFinished` carries `task`, `fileId`, `fileName`, `rows`, `errorCode` and `succeeded()`; it is dispatched once per task and only when `export.events.finished` is true. `ExportProgressed` follows `progress.{enabled,min_rows,step_percent}`.
- `ExportRegistry` is extendable (`keys`/`has`/`get`, `registerUsing`); `Helpers\ResourceExportable` derives columns and children from a model's API resource and filter. Column discovery is deterministic (rows ordered by key, a blank model for nested objects, nested keys win over a flat null, table columns for an empty table) and the discovered keys are cached for 10 minutes so the request and the worker agree. A column missing from the definition when the worker runs is written as an empty column.
- `Column::relations()` and `ResourceExportable`'s `$with` accept Eloquent `with()` shapes (`name => Closure`); a constraint on the child relation is kept next to the child ordering.
- The cap and the progress total share one row-count query.
- `style` config: date and date-time formats, xlsx font, header, number formats, column widths; `Column::date()`. `ttl_hours` is a required setting.
- Fix: the temporary file is created inside the guarded block, so a failed `tempnam()` still releases the slot.
- Removed the unused `illuminate/console` requirement.

## 0.2.0

- Fix: a throwing `ExportFinished` listener no longer turns a completed export into a failed one and deletes its file.
- Fix: the list endpoint no longer calls the storage disk once per item (`download_url` no longer checks the file; `download` still answers 410 when it is gone).
- Fix: concurrent POSTs can no longer exceed `max_active_per_user` (per-owner cache lock); the quota is checked before the filter and row count are evaluated.
- Breaking: definition is now `GET {prefix}/definition?exportable={key}` (no route parameter that hosts may constrain).
- Breaking: the runtime `QueryFilterCompatibility` gate is removed; the Composer constraint is the single source of truth.
- xlsx uses OpenSpout (streamed, flat memory): default cap raised from 50k to 500k rows. Removes `maatwebsite/excel`.
- Exportables can be registered through `config('export.exportables')`; `ExportRegistry::keys()` lists them (hosts use it to create one `<key>.export` permission per document).
- Internals: `ExportPlan`, `GenerateExport`, `RetryExport`, per-format writers, state transitions on `DataExport`, simpler stale/active scopes, one row-count query for the cap with children, extra `(owner_id, created_at)` index.

## 0.1.1

- Fix: `POST {prefix}` rejected `data.*` (filter, sort, search) with 422 "prohibited" in hosts that enable `FormRequest::failOnUnknownFields()`.
- Docs: host integration gotchas.

## 0.3.0

- Async document export (csv, xlsx, pdf) on `alifcoder/query-filter`.
- Endpoints: definition, create, show, download, list, delete/cancel, retry.
- `ExportFinished` event for host notifications.
- Row cap counts flattened output rows at submit (422) and while streaming.
- Download re-checks the permission.
- CI, Pint and PHPStan (level 5) quality gate; opt-in stress test (`--group stress`).
- Fixed: child detection never calls a model method that the model class does not declare as a `HasMany`, so a resource key such as `deleted` no longer reaches an Eloquent method of that name; the discovery sample falls back to an unordered query when the filter's own query cannot be ordered by the key, and a stored row whose resource fails is skipped while discovering. File names of dotted keys read `sale_sale_...` instead of `salesale_...`.
