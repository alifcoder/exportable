# alifcoder/exportable

Async document export (csv, excel) for Laravel, built on `alifcoder/query-filter` (`^2.0.2`, enforced by Composer).

The package is host-driven and **stateless**: it ships **no routes, no controllers, no database table, no schedule, no config defaults and no file storage**. The host provides the configuration, the endpoints, the permission check, the place files are kept and the list of finished exports.

## Flow
The host's filter uses `Concerns\HandlesExport`; its endpoint calls `$filter->export()` after authorising the caller. On that request, and before any job exists, the package: validates `format` and `columns` (the host's filter validates its own parameters when it builds the query), takes the host's ready query (filter applied, so the caller's data limits are in it), checks every requested column against what the resource yields for a sample of rows (and reads each column's type from it), counts the rows once (cap, empty check and progress total) and compiles the query into SQL + bindings. `export()` hands the package the filter object itself (`ExportDTO(source, format, columns)`, the source implements `Contracts\ExportableFilter`); the package calls its public `exportQuery()` once and `exportResource()`, never re-creates or re-applies the filter and never changes the received query. Objects are not queued: the task keeps only the filter's class name (diagnostics) and a `RowSource` (model, resource, eager-load names). It then queues `RunExport` with an `ExportTask` (id, the prepared query, columns, row total).

The job needs no signed-in user and does not rebuild the filter: it reads the prepared SQL page by page (`chunk_size` rows at a time, `limit/offset` after the stable order), hydrates the models, eager loads the relations the host's query eager loads, renders each model through the resource, writes a **local temporary file**, hands it to the host's `ExportFileStore`, removes the temporary file and dispatches `ExportFinished` (completed or failed, with an error code). While it runs, `ExportProgressed` events tell the client how far it is. Nothing is persisted by the package; the host keeps whatever it needs from `ExportFinished`.

Paging is by offset over the host's ORDER BY (the host ends it with the key, so chunks never repeat or skip a row), so rows inserted or deleted *while the job runs* can shift a page. The job appends `limit n offset m` to the compiled SQL text of each chunk. Only PostgreSQL (`pgsql`) is supported; any other driver is rejected with `invalid_registration`.

## Install
1. `composer require alifcoder/exportable`
2. `php artisan vendor:publish --tag=export-config`, set **every** key in `config/export.php` (a missing one fails with `Missing export configuration "export.<key>"`).
3. Bind `Contracts\ExportFileStore`: `put(ExportTask, localPath, fileName, mime): string` (returns your file id). Links, listing and deletion are the host's. There is no auth contract and no gate/ability/guard setting.
4. Use `Concerns\HandlesExport` in your filter base class and implement `Contracts\ExportableFilter` (the two public hooks below). The filter provides:
   - `params()`: the request data the filter was built from;
   - `fields()`: `name => Field` (or a bare name). A document column must be one of its names, otherwise 422 on `columns`. A `Field` with `->type(FieldType::X)` (`alifcoder/query-filter` `^2.0.2`, `Field::$type`) sets its column's cell type (Uuid and String are text; Integer, Decimal, Boolean, Date, DateTime, Time map to the same-named type); without a type the type is guessed from the sampled values; a bare name has no type;
   - `exportLists()` (optional): `['products' => [ItemFilter::class, 'serials' => SerialFilter::class]]`, the filter that reads each list's items, whose `fields()` names its columns; no list by default;
   - `public exportQuery(): Builder`: the list's **ready** query: filter applied, **no limit and no offset** and a **stable ORDER BY** (the filter's sort, then the key). The package reads it as given: it never changes it and never adds a limit, offset or ordering. A query with a limit/offset or without an ORDER BY fails with `unpageable_query` (500) and nothing is queued;
   - `public exportResource(): string`: the resource that renders one row, as the list shows it.

   Eager loads follow the builder: the names in `exportQuery()->getEagerLoads()` are recorded in the task and the job loads them; a constrained eager load (a closure, or `relation:column`) cannot be repeated and fails with `unsupported_eager_load` (500). Write the endpoint, authorise the caller there, call `$filter->export()`, and listen to `ExportFinished`.
5. Run a queue worker for `queue.name` with enough memory/timeout for xlsx. The queue connection's `retry_after` must exceed `queue.timeout`, otherwise a second worker picks up the running job.
6. Authorize the private channel `export.{id}` (the id of the returned `ExportTask`) if you use progress events.
7. The job runs without a request or a user, so the filter's resource must not depend on either to render a row.

## Request
The list's own parameters plus two export parts, at the top level:
```json
{ "format": "excel",
  "columns": { "prefix-index": "Document Number", "outlet.name": "Outlet Name",
               "products": { "sequence": "Sequence", "product.name": "Product", "quantity": "Quantity",
                             "serials": { "imei": "IMEI" } } },
  "filter": {}, "sort": "-document_date", "search": "abc" }
```
- `format`: `csv` or `excel` (a `.xlsx` file).
- `columns`: resource key (dotted for nested objects) => heading. A list the resource renders (`products`) takes its own `key => heading` map, the keys read from each item; it may hold further lists (`serials`), up to 5 levels, and any number of sibling lists.  The stored file is named `<task id>.<ext>`; the client chooses the download name.

A column whose last segment ends in `_id` (`business_partner_id`, `address.region_id`, `lines.focus_product_id`) is never exported, even when the filter offers it: use a dotted field of the related record (`business_partner.name`). `id` is allowed.

Every failure of `export()` is an `ExportException` (`errorCode`, `status`, `errors`, `isClientError()`); the package defines no `render()`. A 422 carries `errors` keyed by request field, a 500 is a set-up or runtime failure:

| `errorCode` | status | `errors` key |
|---|---|---|
| `invalid_request` (`format`/`columns` missing or invalid) | 422 | the invalid fields |
| `needs_document_column`, `nested_too_deep` | 422 | `columns` |
| `columns_refused` (column/list not offered, `*_id` column; all reported together) | 422 | `columns` |
| `unknown_column` (the resource does not yield a document column) | 422 | `columns` |
| `no_data` | 422 | `columns` |
| `too_many_rows` | 422 | `format` |
| `unpageable_query`, `unsupported_eager_load`, `resource_failed`, `invalid_registration`, `invalid_configuration` | 500 | none |

Columns are kept in the order the client sent them (document columns, then each list's, to any depth). A document column the resource does not yield is `unknown_column`. A list item column is first checked strictly against the filter (when the list is declared in `exportLists()` at that depth, the column must be in that item filter's `fields()` and not end in `_id`, otherwise `column_not_offered` / `column_not_exportable`; a list not declared there is not checked and its columns are simply tried); it is tried on the resource and an item that does not yield it gets an empty cell, in the request check and in the job alike. A value that is not plain (array, object) fails the job with `invalid_column_value`. A list the filter declares but the resource does not render is accepted and writes no sub rows. A `Field` type wins over the guess from sample values; an empty-only column has no guessed type and is written as empty text.

## Config (all required)
| Key | Meaning |
|---|---|
| `ttl_hours` | hours a finished export stays downloadable |
| `chunk_size`, `max_rows.{csv,xlsx}` | paging, caps |
| `queue.{connection,name,timeout}` | `connection`/`name` may be `null` but must be present |
| `stale_margin_seconds` | the "finished" announcement of an export is remembered `queue.timeout` + this many seconds, so it is sent once |
| `events.finished` | dispatch `ExportFinished` when an export completes or fails; `false` silences it |
| `progress.{enabled,min_rows,step_percent}` | broadcast `ExportProgressed` for exports of at least `min_rows` rows, at most every `step_percent` points, and a final 100 once the last row is written (the finished event then announces the file) |
| `style.{date_format,datetime_format,time_format}` | PHP `date()` formats of dates in csv |
| `style.boolean.{true,false}` | text of boolean cells in csv and xlsx; a translation key is translated |
| `style.csv.{delimiter,enclosure,line_ending,bom}` | csv field separator and quote (single byte), row ending, UTF-8 byte order mark |
| `style.xlsx.font`, `.header`, `.column_width` | font, header look (ARGB colours), width = longest heading or value (sub rows included) + padding, clamped to min..max; cells and headings are centered |
| `style.xlsx.{integer_format,number_format}` | Excel number formats of whole and decimal cells |
| `style.xlsx.{date_format,datetime_format,time_format}` | Excel formats (Excel syntax, e.g. `yyyy-mm-dd`) of date cells |

## Column types
A cell is written as its column's `Alif\QueryFilter\Enums\FieldType`: the `type()` of the column's `Field`, or, when the `Field` has none, a type read from the values the resource yields for a sample of rows: the date (`2026-03-04`) and date-time (`2026-03-04T05:06:07Z`) text a resource renders, and a decimal numeric string by its key (`*_amount`, `*_price`, ...). A column whose value has no recognisable type is read from each value.

| Type | xlsx cell | csv |
|---|---|---|
| `String`, `Uuid` | text, never a formula | text, a leading `=` `+` `-` `@` is neutralised |
| `Integer`, `Decimal` | number with `integer_format` / `number_format` | the value as it came |
| `Boolean` | text from `style.boolean` | text from `style.boolean` |
| `Date`, `DateTime`, `Time` | a real Excel date with `xlsx.date_format`, `datetime_format`, `time_format` | text with the PHP formats of `style` |

Dates arriving as text are parsed and shown in the `app.timezone`. A value that does not fit its type (text in a number column, a number over 15 digits, an unparsable date) is written as exact text, never rounded or dropped.

## Internals
One export is two steps, on the request and in the queued job; the whole filtered set is exported as one page: the host's `exportQuery()` must have no limit/offset, the package does not drop one.

| Class | Step | What it does |
|---|---|---|
| `Services\ExportService` | request | runs the steps below in order and queues `RunExport` |
| `Helpers\RequestedColumns` | request | reads `columns` in order, checks each level against `fields()`/`exportLists()`, refuses `*_id` columns, takes the cell type from `Field::$type` |
| `Helpers\ExportQuery` | request | refuses a query with a limit/offset or without ORDER BY; counts it (on clones); refuses an empty result or one over the format's cap |
| `Helpers\ColumnResolver` | request | renders a sample of rows through the resource, refuses a document column the resource does not yield (list item columns are tried, empty when missing), gives each column its type (`Helpers\CellType::guess()`) |
| `Helpers\ResourceRows` | both | reads a model through its resource into dotted keys (`partner.name`) and lists (`products`) |
| `Helpers\DocumentReader` | job | reads the prepared SQL `chunk_size` rows at a time and turns each document into `Line`s (own line, then heading and item lines of each list) |
| `Helpers\ColumnNode` | job | the requested columns as a tree (document, lists, lists in lists): headings, types, widths |
| `Helpers\ExportProgress` | job | passes documents through and broadcasts `ExportProgressed` |
| `Helpers\ExportStyle`, `Helpers\Writers\*` | job | the `style.*` settings; `CsvWriter` / `XlsxWriter` write the `Line`s |
| `Helpers\ExportConfig` | both | reads `config/export.php`; a missing key is an error, the package has no defaults |

## Messages
The package ships its messages in `uz`, `ru` and `en` (`export::messages.*`): the 422 messages of the request (`no_data`, `too_many_rows`, `unknown_column`, `column_not_offered`, `list_not_offered`, `column_not_exportable`, `needs_document_column`, `nested_too_deep`, `invalid_request`) and one text per `errorCode` of a failed `ExportFinished` (`export::messages.errors.<code>`), which the host can show to the person who asked (the package itself only reports the code). The package sets no locale: messages use the locale of the request, and job-time texts the worker's default. Override or add a language by publishing them (`php artisan vendor:publish --tag=export-lang`) to `lang/vendor/export`.

## Events
- `ExportFinished` (`task`, `fileId`, `fileName`, `rows`, `errorCode`; `succeeded()` is `errorCode === null`) when an export is written or has failed. Switch off with `events.finished`.
- `ExportProgressed` (`ShouldBroadcastNow`, private channel `export.{id}`, `export.progressed`): `id`, `percent`, `rows`, `total_rows`.

## Lists (`products`)
A list column group is written as sub rows under the document row, like the expandable list in the app:

- xlsx: the document row, then for each list a heading row and a row per item, each item followed by its own lists. Sub rows sit one column further right per level and are grouped into an Excel outline, collapsed: the `+` beside a document row opens its items, the `+` beside an item opens its sub list. A list without items writes nothing (no `+`).
- csv: the same rows, without grouping; a sub row is shifted right by its depth.

Rules: the document needs at least one column of its own; relations of list items must be eager loaded by `exportQuery()` (`products.product`, `products.serials`), as lazy loading is blocked; a list that is empty in every sampled document is accepted with its keys untyped; a list of scalars (`tags`) is not a list.

Counts: the request-time cap, empty check and progress total count documents. The cap is enforced again on written rows (sub rows and their heading rows included) while writing: a file pushed over it fails with `row_limit_exceeded`. `ExportProgressed.rows` counts documents; `ExportFinished.rows` counts written rows. xlsx grouping rewrites the sheet's row tags after writing, so large files take a few seconds longer.

## Layout and safety
- Caps count output rows. csv: formula-prefix guard on non-numeric columns; layout (delimiter, enclosure, line ending, BOM) from `style.csv`. xlsx: non-numeric cells are inert strings.
- Lazy loading is guarded best-effort while exporting (also while the column sample is rendered): the relations the resource touches must be eager loaded by `exportQuery()`, plain (no constraint). The package arms every row it hydrates, and Eloquent arms related models loaded in sets of more than one; a related model loaded alone (a single to-one relation) is not guarded, so a missing eager load there is a silent extra query, not an error. A resource that throws fails with `resource_failed` (500, reported).

## Reading large exports
The job reads each chunk by re-running the SQL with `limit n offset m`, so every chunk repeats the filter and the sort: a 500k-row export at `chunk_size` 500 is 1000 queries and the cost grows quadratically. A database cursor was evaluated and not adopted: `cursor()` cannot eager load and `pdo_pgsql` buffers the whole result; `chunkById()`/`lazyById()` re-order the query by key. A PostgreSQL server-side cursor (`DECLARE ... NO SCROLL CURSOR`, `FETCH n`, one `REPEATABLE READ READ ONLY` transaction) would sort once and keep a consistent snapshot, at the price of a transaction open for the whole job (vacuum bloat, `idle_in_transaction_session_timeout`, pgbouncer pooling mode). It is a possible follow-up behind a config switch, after a benchmark.

## Performance and sizing
Measured earlier (Postgres 16): csv 500k rows in 54s at 48MB peak, xlsx 500k rows in 55s at 48MB peak.

## Development
```
composer check      # pint --test, phpstan (level 5)
vendor/bin/pint     # fix style
```
