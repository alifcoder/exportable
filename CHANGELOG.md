# Changelog

## Unreleased

Added:

- List columns: `columns` may hold `list => {key => heading}` (e.g. `products`), nested to 5 levels and as many sibling lists as wanted. They are written as sub rows below their document row; in xlsx as a collapsed Excel outline (`+` opens them), in csv indented by depth. `ExportColumn` gains `groups`, `Writers\Line` and `Helpers\ColumnNode` are new.

Changed:

- xlsx: cells and headings are centered, and a column is as wide as its longest heading or value (it was the heading only), clamped to `column_width.min..max`.
- xlsx: the header font color is passed as RGB (the ARGB config value was doubled to an invalid color). The empty comments parts the xlsx library adds are removed (WPS based editors refuse them), and matching `XlsxPackage` finishes the file.

Breaking:

- `HandlesExport::exportWindow()` hook is removed: the export always writes the whole filtered set, in one sheet. A limit/offset left on the list query is dropped, `PreparedQuery` loses `offset` and `limit` (queued payloads still carrying them are read fine).
- `ExportRequest` is renamed `ExportDTO`.
- `Helpers\ExportBuilder` is split by what it does: `ExportQuery` (whole set, count, empty and cap checks), `ColumnResolver` (checks the columns against the resource, gives their types) and `DocumentReader` (reads the prepared SQL into lines in the job). `ResourceRows::typeOf()` is `ColumnTypeEnum::guess()`.
- No limit on the number of columns: the `max:200` rule and `too_many_columns` message are gone.
- The package ships its messages (`lang/{uz,ru,en}/messages.php`, `export::messages.*`): the request's 422 messages and a text per error code. Publish with the `export-lang` tag to override.
- A requested column must be offered by the filter, otherwise 422 on `columns` (`Column "x" is not offered by the filter.`) before any query runs: a document column is one of its `fields()` names; a list column is one of the `fields()` names of the item filter named by the optional `exportLists()` hook (`list => ItemFilter::class`, or `list => [ItemFilter::class, 'inner' => InnerFilter::class]`; no list by default). The resource check still follows.
- The filter provides `query()` (abstract in `HandlesExport`) and the export starts from it; the `exportQuery()` hook is removed.
- `ExportBuilder::rows()` is `documents()` (one list of `Line`s per document); `ResourceRows::values()` is `read()` and `layout()` returns nested `values` and `lists`. `Writer::write()` takes `Line`s and the column widths. `ExportColumn::toArray()` carries `groups`.
- `ExportRequest`/`ExportCreateDTO` lose `childRelation` and `childColumns` (lists are now columns with a `group`), `ExportBuilder::assertChildRelation()` is removed.
- The package no longer knows who asked: `HandlesExport::export()` and `ExportServiceInterface::create()` take no owner, `ExportTask::$ownerId` is gone. With it go the per-owner quota (`ExportQuota`, `max_active_per_user`, `ExportException::tooManyActive()`/`forbidden()`/`ownerMissing()`, `httpStatus()`/`report()`/`render()`). `stale_margin_seconds` now only sets how long the "finished" announcement is remembered.
- `ExportProgressed` broadcasts on the private channel `export.{id}` (was `App.Models.User.{ownerId}`) and has no `ownerId`.
- `Writer::write()` takes no title; the xlsx sheet keeps the default name. `ExportFileName` is removed: the stored file is named `<task id>.<ext>` instead of after the filter class.
- `ExportWriter` is replaced by `ExportFormatEnum::writer()` and `StartExport` is folded into `ExportService`.
- `ExportTask::$locale` is gone: exports are always written in the worker's default locale, `WriteExport` no longer switches it. Queued payloads still carrying `locale` are read fine (the key is ignored).

## 0.6.0

The export is requested through the filter and settled before the job exists. The job reads a prepared query; it needs no signed-in user.

Breaking:

- New `Concerns\HandlesExport` trait (moved from the host) and `Contracts\ExportSource`. Request: top-level `format` (`csv` | `excel`) and `columns` (`key => heading`, one nested object = a HasMany relation's columns). `title`, `include_children`, `child_columns` and the `export` wrapper are gone; the file name comes from the filter class.
- `ExportFormatEnum::XLSX` (`xlsx`) is `ExportFormatEnum::EXCEL` (`excel`, still a `.xlsx` file); the `max_rows.xlsx` config key is unchanged.
- Removed `Exportable`, `ExportRegistry`, `ResourceExportable`, `ExportPlan`, `Column` and `ExportServiceInterface::definition()`. Columns are checked against the resource's output for a sample of rows on the request, which also sets their types.
- `ExportServiceInterface::create()` takes an `ExportRequest` (source, format, the applied query, columns). `ExportCreateDTO` carries the compiled SQL, bindings and window (`PreparedQuery`) and typed `ExportColumn`s.
- The job pages the prepared SQL by `limit/offset` in `chunk_size` rows (it used to snapshot the keys and reload them with the rebuilt filter). The `queue.middleware` config key and `RunExport::middleware()` are removed: the job needs no signed-in user, so there is nothing to wrap.
- `ExportException::unknownExportable()` and `unknownColumn()` are gone; an empty result and an unknown column are 422 validation errors on `columns`.

## 0.5.0

Cell types: `Enums\ColumnTypeEnum` (`String`, `Number`, `Integer`, `Decimal`, `Percent`, `Currency`, `Boolean`, `Date`, `DateTime`, `Time`), `Column::as()`, and a `types` map on `ResourceExportable`. A column without a type reads each value; `ResourceExportable` recognises dates, booleans and numbers from the resource output. xlsx writes real Excel dates; boolean text, percent, currency, time and date formats are configurable.

Breaking:

- New required config keys: `style.time_format`, `style.boolean.{true,false}`, `style.xlsx.{percent_format,currency_format,date_format,datetime_format,time_format}`.
- `Writer::write()` and `ExportWriter::store()` take a list of `?ColumnTypeEnum` instead of a list of numeric flags; `ExportPlan::numeric()` is `types()`; `Column::isDate()` is gone (`type()`).
- Values reach the writers typed: a date stays a date object and a boolean stays a boolean (it used to be formatted text and `1`/`0`); the writer formats them. A column without a type now writes ints and floats as numbers and booleans as `style.boolean` text.
- `ExportBuilder` no longer takes an `ExportStyle`.

## 0.4.0

Breaking:

- `ExportProgressed` broadcasts on the owner's `App.Models.User.{id}` private channel instead of `exports.{ownerId}`.
- New required config keys `style.csv.{delimiter,enclosure,line_ending,bom}`; the csv layout was hard-coded before.

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
