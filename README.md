# alifcoder/exportable

Async document export (csv, excel) for Laravel, built on `alifcoder/query-filter` (`^2.0.2`, enforced by Composer).

The package is host-driven and **stateless**: it ships **no routes, no controllers, no database table, no schedule, no config defaults and no file storage**. The host provides the configuration, the endpoints, the permission check, the place files are kept and the list of finished exports.

## Flow
The host's model filter uses `Concerns\HandlesExport`; its endpoint calls `$filter->export()` after authorising the caller. On that request, and before any job exists, the package: validates `format` and `columns` (the host's filter validates its own parameters when it applies itself), takes the filter's ready query (built by a copy of the filter without the request's page parameters and applied, so the caller's data limits are in it, no limit or offset; ended with the model's key), checks every requested column and list against what the resource yields for a sample of rows (and takes each column's type from it), counts the rows once (cap, empty check and progress total) and compiles the query into SQL + bindings. `export()` hands the package `ExportDTO(query, resource, format, columns)`; the package never re-applies the filter to the query it hands over. Objects are not queued: the task keeps only the model, resource and eager-load names. It then queues `RunExport` with an `ExportTask` (id, the prepared query, columns, row total).

The job needs no signed-in user and does not rebuild the filter: it reads the prepared SQL page by page (`chunk_size` rows at a time, `limit/offset` after the stable order), hydrates the models, eager loads the relations the host's query eager loads, renders each model through the resource, writes a **local temporary file**, hands it to the host's `ExportFileStore`, removes the temporary file and dispatches `ExportFinished` (completed or failed, with an error code). While it runs, `ExportProgressed` events tell the client how far it is. Nothing is persisted by the package; the host keeps whatever it needs from `ExportFinished`.

Paging is by offset over the host's ORDER BY, which the package ends with the model's key (when the key does not end it already; an unsorted query is ordered by the key; a `SELECT DISTINCT` is left as the host wrote it), so chunks never repeat or skip a row, so rows inserted or deleted *while the job runs* can shift a page. The job appends `limit n offset m` to the compiled SQL text of each chunk. Only PostgreSQL (`pgsql`) is supported; any other driver is rejected with `invalid_registration`.

## Install
1. `composer require alifcoder/exportable`
2. `php artisan vendor:publish --tag=export-config`, set **every** key in `config/export.php` (a missing one fails with `Missing export configuration "export.<key>"`).
3. Bind `Contracts\ExportFileStore`: `put(ExportTask, localPath, fileName, mime): string` (returns your file id). Links, listing and deletion are the host's. There is no auth contract and no gate/ability/guard setting.
4. Use `Concerns\HandlesExport` in any `ModelFilter` (a filter with a constructor taking the parameters array, `params()`, `query()`, `apply()`, `isShort()` and a static `$resource`; `static::$shortResource` and `fields()` are read when it has them). Nothing else is implemented:
   - the query is `query()` with the filter applied; `export()` builds it from a copy of the filter without the request's `limit`, `offset`, `paginate`, `per_page` and `page`, so the list keeps using them. The package ends its order with the model's key (an unsorted query is ordered by it), so the host's sort may tie; a query with a limit/offset, `GROUP BY`/`HAVING` or a `UNION` is refused as `unpageable_query` (500) and nothing is queued;
   - the resource is the filter's `static::$resource`.

   Eager loads follow the builder: the names in the query's `getEagerLoads()` are recorded in the task and the job loads them; a constrained eager load (a closure, or `relation:column`) cannot be repeated and fails with `unsupported_eager_load` (500). Write the endpoint, authorise the caller there, call `$filter->export()`, and listen to `ExportFinished`.
5. Run a queue worker for `queue.name` with enough memory/timeout for xlsx. The queue connection's `retry_after` must exceed `queue.timeout`, otherwise a second worker picks up the running job.
6. Authorize the private channel `export.{id}` (the id of the returned `ExportTask`) if you use progress events.
7. The job runs without a request or a user, so the filter's resource must not depend on either to render a row.

## Request
The list's own parameters plus two export parts, at the top level (and optionally `with`, a list of relation names added to the filter's eager loads before the query is built; an unknown name is a 422 on `with`):
```json
{ "format": "excel",
  "columns": { "prefix-index": "Document Number", "outlet.name": "Outlet Name",
               "products": { "sequence": "Sequence", "product.name": "Product", "quantity": "Quantity",
                             "serials": { "imei": "IMEI" } } },
  "filter": {}, "sort": "-document_date", "search": "abc" }
```
- `format`: `csv` or `excel` (a `.xlsx` file).
- `with` (optional): relation names (dotted for nested ones) the client wants loaded for a column the list does not load by itself. Each must be a relation of the model, else `invalid_request` (422) on `with`. The package only checks them; adding them to the filter (`setWith()`) is the host's, before it applies the filter.
- `limit`, `offset`, `paginate`, `per_page` and `page` are ignored: the whole filtered set is exported.
- `columns`: resource key (dotted for nested objects) => heading. A list the resource renders (`products`) takes its own `key => heading` map, the keys read from each item; it may hold further lists (`serials`), up to 5 levels, and any number of sibling lists.  The stored file is named `<task id>.<ext>`; the client chooses the download name.

A column is exported whenever the resource yields it (a foreign key such as `business_partner_id` too); only a document column or list the resource does not yield is refused.

Every failure of `export()` is an `ExportException` (`errorCode`, `status`, `errors`, `isClientError()`); the package defines no `render()`. A 422 carries `errors` keyed by request field, a 500 is a set-up or runtime failure:

| `errorCode` | status | `errors` key |
|---|---|---|
| `invalid_request` (`format`/`columns` missing or invalid) | 422 | the invalid fields |
| `nested_too_deep` | 422 | `columns` |
| `unknown_column` (the resource does not yield a document column) | 422 | `columns` |
| `no_data` | 422 | `columns` |
| `too_many_rows` | 422 | `format` |
| `unpageable_query`, `unsupported_eager_load`, `resource_failed`, `invalid_registration`, `invalid_configuration` | 500 | none |

Columns are kept in the order the client sent them (document columns, then each list's, to any depth). Everything is checked against the resource, nothing against the filter's `fields()`: a document column or a list the resource does not yield is `unknown_column`; a list item column is tried on the resource and an item that does not yield it gets an empty cell, in the request check and in the job alike. A list that is empty in every sampled document shows no keys, so its columns are accepted. A list of scalars is one cell, its items joined with ", "; a value that is not plain (array, object) is written empty. A field the resource yields only for some rows is looked for in chunks of 50 rows (up to 500) before the column is refused. The cell type is guessed from the sampled values; an empty-only column has no guessed type and is written as empty text.

## Config (all required)
| Key | Meaning |
|---|---|
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
One export is two steps, on the request and in the queued job; the whole filtered set is exported as one page: `export()` drops the request's `limit`/`offset`. With `short=1` the rows are rendered with the filter's `$shortResource` (when it has one), like the list.

| Class | Step | What it does |
|---|---|---|
| `Concerns\HandlesExport` | request | all validation, before anything is queued: `format`/`columns`; the query (limit/offset, group/union, the key order, plain eager loads, one count for progress total, empty and cap checks); `columns` (flattens them in order, limits list depth, wants a document column, renders a sample of rows through the resource, refuses a document column it does not yield — list item columns are tried, empty when missing — and types each cell with `Helpers\CellType::guess()`) |
| `Services\ExportService` | request | turns the validated `ExportDTO` into an `ExportTask` (the query compiled to SQL + bindings) and queues `RunExport` |
| `Helpers\ResourceRows` | both | reads a model through its resource into dotted keys (`partner.name`) and lists (`products`) |
| `Helpers\DocumentReader` | job | reads the prepared SQL `chunk_size` rows at a time and turns each document into `Line`s (own line, then heading and item lines of each list) |
| `Helpers\ColumnNode` | job | the requested columns as a tree (document, lists, lists in lists): headings, types, widths |
| `Helpers\ExportProgress` | job | passes documents through and broadcasts `ExportProgressed` |
| `Helpers\ExportStyle`, `Helpers\Writers\*` | job | the `style.*` settings; `CsvWriter` / `XlsxWriter` write the `Line`s |
| `Helpers\ExportConfig` | both | reads `config/export.php`; a missing key is an error, the package has no defaults |

## Messages
The package ships its messages in `uz`, `ru` and `en` (`export::messages.*`): the 422 messages of the request (`no_data`, `too_many_rows`, `unknown_column`, `nested_too_deep`, `invalid_request`) and one text per `errorCode` of a failed `ExportFinished` (`export::messages.errors.<code>`), which the host can show to the person who asked (the package itself only reports the code). The package sets no locale: messages use the locale of the request, and job-time texts the worker's default. Override or add a language by publishing them (`php artisan vendor:publish --tag=export-lang`) to `lang/vendor/export`.

## Events
- `ExportFinished` (`task`, `fileId`, `fileName`, `rows`, `errorCode`; `succeeded()` is `errorCode === null`) when an export is written or has failed. Switch off with `events.finished`.
- `ExportProgressed` (`ShouldBroadcastNow`, private channel `export.{id}`, `export.progressed`): `id`, `percent`, `rows`, `total_rows`.

## Lists (`products`)
A list column group is written as sub rows under the document row, like the expandable list in the app:

- xlsx: the document row, then for each list a heading row and a row per item, each item followed by its own lists. Sub rows sit one column further right per level and are grouped into an Excel outline, collapsed: the `+` beside a document row opens its items, the `+` beside an item opens its sub list. A list without items writes nothing (no `+`).
- csv: the same rows, without grouping; a sub row is shifted right by its depth.

Rules: the document needs at least one column of its own; relations of list items must be eager loaded by the filter (`$with`) (`products.product`, `products.serials`), as lazy loading is blocked; a list that is empty in every sampled document is accepted with its keys untyped; a list of scalars (`tags`) is not a list.

Counts: the request-time cap, empty check and progress total count documents. The cap is enforced again on written rows (sub rows and their heading rows included) while writing: a file pushed over it fails with `row_limit_exceeded`. `ExportProgressed.rows` counts documents; `ExportFinished.rows` counts written rows. xlsx grouping rewrites the sheet's row tags after writing, so large files take a few seconds longer.

## Layout and safety
- A text longer than Excel's cell limit (32,767 characters) is cut there in xlsx, and the characters XML does not allow (U+FFFE, U+FFFF) are dropped, so one value never fails or corrupts the file. csv keeps the text as it is.
- A column's cell type is the `FieldType` of the filter's field of the same name (`Field::type()`, read from the filter's `fields()` when it has one), else the type guessed from the values.
- A query with `GROUP BY`, `HAVING` or `UNION` is refused as `unpageable_query`: the job pages by `limit`/`offset`, which is not stable over them.
- Query bindings are saved as the connection sends them (dates as text in the app's wall clock time), so the queued payload means the same after any queue driver stores it, JSON included.
- Caps count output rows. csv: formula-prefix guard on non-numeric columns; layout (delimiter, enclosure, line ending, BOM) from `style.csv`. xlsx: non-numeric cells are inert strings.
- Lazy loading is guarded best-effort while exporting (also while the column sample is rendered): the relations the resource touches must be eager loaded by the filter (`$with`), plain (no constraint). The package arms every row it hydrates, and Eloquent arms related models loaded in sets of more than one; a related model loaded alone (a single to-one relation) is not guarded, so a missing eager load there is a silent extra query, not an error. A resource that throws fails with `resource_failed` (500, reported).

## Reading large exports
The job reads each chunk by re-running the SQL with `limit n offset m`, so every chunk repeats the filter and the sort: a 500k-row export at `chunk_size` 500 is 1000 queries and the cost grows quadratically. A database cursor was evaluated and not adopted: `cursor()` cannot eager load and `pdo_pgsql` buffers the whole result; `chunkById()`/`lazyById()` re-order the query by key. A PostgreSQL server-side cursor (`DECLARE ... NO SCROLL CURSOR`, `FETCH n`, one `REPEATABLE READ READ ONLY` transaction) would sort once and keep a consistent snapshot, at the price of a transaction open for the whole job (vacuum bloat, `idle_in_transaction_session_timeout`, pgbouncer pooling mode). It is a possible follow-up behind a config switch, after a benchmark.

## Performance and sizing
Measured earlier (Postgres 16): csv 500k rows in 54s at 48MB peak, xlsx 500k rows in 55s at 48MB peak.

## Development
```
composer check      # pint --test, phpstan (level 5)
vendor/bin/pint     # fix style
vendor/bin/phpunit  # unit tests
```
