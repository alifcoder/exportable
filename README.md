# alifcoder/exportable

Async document export (csv, excel) for Laravel, built on `alifcoder/query-filter` (`^2.0.1`, enforced by Composer).

The package is host-driven and **stateless**: it ships **no routes, no controllers, no database table, no schedule, no config defaults and no file storage**. The host provides the configuration, the endpoints, the permission check, the place files are kept and the list of finished exports.

## Flow
The host's filter uses `Concerns\HandlesExport`; its endpoint calls `$filter->export()` after authorising the caller. On that request, and before any job exists, the package: validates `format` and `columns` and the filter's own parameters, applies the filter (so the caller's data limits are in the query), checks every requested column against what the filter's resource yields for a sample of rows (and reads each column's type from it), counts the rows once (cap, empty check and progress total) and compiles the query into SQL + bindings. It then queues `RunExport` with an `ExportTask` (id, locale, the prepared query, columns, row total).

The job needs no signed-in user and does not rebuild the filter: it reads the prepared SQL page by page (`chunk_size` rows at a time, `limit/offset` after the stable order), hydrates the models, eager loads the filter's `$with` relations, renders each model through the resource, writes a **local temporary file**, hands it to the host's `ExportFileStore`, removes the temporary file and dispatches `ExportFinished` (completed or failed, with an error code). While it runs, `ExportProgressed` events tell the client how far it is. Nothing is persisted by the package; the host keeps whatever it needs from `ExportFinished`.

Paging is by offset over a query ordered by the filter's sort and then the key, so rows inserted or deleted *while the job runs* can shift a page. Only PostgreSQL (`pgsql`) is supported; any other driver is rejected with `invalid_registration`.

## Install
1. `composer require alifcoder/exportable`
2. `php artisan vendor:publish --tag=export-config`, set **every** key in `config/export.php` (a missing one fails with `Missing export configuration "export.<key>"`).
3. Bind `Contracts\ExportFileStore`: `put(ExportTask, localPath, fileName, mime): string` (returns your file id). Links, listing and deletion are the host's. There is no auth contract and no gate/ability/guard setting.
4. Use `Concerns\HandlesExport` in your filter base class and implement `Contracts\ExportSource` (the trait provides `exportSource()`). The filter must provide `static $model`, `static $resource`, `params()`, `apply()` and `getWith()`. Optional hooks: `exportQuery()` (start from the filter's own selects/counts/ordering instead of the bare model) and `exportWindow($query)` (limit to the page the list shows). Write the endpoint, authorise the caller there, call `$filter->export()`, and listen to `ExportFinished`.
5. Run a queue worker for `queue.name` with enough memory/timeout for xlsx. The queue connection's `retry_after` must exceed `queue.timeout`, otherwise a second worker picks up the running job.
6. Authorize the private channel `export.{id}` (the id of the returned `ExportTask`) if you use progress events.
7. The job runs without a request or a user, so the filter's resource must not depend on either to render a row.

## Request
The list's own parameters plus two export parts, at the top level:
```json
{ "format": "excel",
  "columns": { "prefix-index": "Document Number", "outlet.name": "Outlet Name",
               "products": { "sequence": "Sequence", "product.name": "Product", "quantity": "Quantity" } },
  "filter": {}, "sort": "-document_date", "search": "abc" }
```
- `format`: `csv` or `excel` (a `.xlsx` file).
- `columns`: resource key (dotted for nested objects) => heading. At most 200 columns, headings 1 to 150 characters. The stored file is named `<task id>.<ext>`; the client chooses the download name.

Errors raised on the request: `ValidationException` (422) for the export rules, the filter's parameters, an unknown column (`columns`), an empty result and the row cap (`format`); `ExportException` `too_many_active` (429).

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
| `style.xlsx.font`, `.header`, `.column_width` | font, header look (ARGB colours), width = heading length + padding clamped to min..max |
| `style.xlsx.{integer_format,number_format,percent_format,currency_format}` | Excel number formats of whole, decimal, percent and currency cells |
| `style.xlsx.{date_format,datetime_format,time_format}` | Excel formats (Excel syntax, e.g. `yyyy-mm-dd`) of date cells |

## Column types
A cell is written as its column's type, read from the values the resource yields for a sample of rows: the date (`2026-03-04`) and date-time (`2026-03-04T05:06:07Z`) text a resource renders, and a decimal numeric string by its key (`*_amount`, `*_price`, ...). A column whose value has no recognisable type is read from each value.

| Type | xlsx cell | csv |
|---|---|---|
| `String` | text, never a formula | text, a leading `=` `+` `-` `@` is neutralised |
| `Number` | whole or decimal by the value (`integer_format` / `number_format`) | the value as it came |
| `Integer`, `Decimal` | number with `integer_format` / `number_format` | the value as it came |
| `Percent` | number with `percent_format` (25 shows as 25.00%, it is not scaled) | the value as it came |
| `Currency` | number with `currency_format` | the value as it came |
| `Boolean` | text from `style.boolean` | text from `style.boolean` |
| `Date`, `DateTime`, `Time` | a real Excel date with `xlsx.date_format`, `datetime_format`, `time_format` | text with the PHP formats of `style` |

Dates arriving as text are parsed and shown in the `app.timezone`. A value that does not fit its type (text in a number column, a number over 15 digits, an unparsable date) is written as exact text, never rounded or dropped.

## Events
- `ExportFinished` (`task`, `fileId`, `fileName`, `rows`, `errorCode`; `succeeded()` is `errorCode === null`) when an export is written or has failed. Switch off with `events.finished`.
- `ExportProgressed` (`ShouldBroadcastNow`, private channel `export.{id}`, `export.progressed`): `id`, `percent`, `rows`, `total_rows`.

## Layout and safety
- Caps count output rows. csv: formula-prefix guard on non-numeric columns; layout (delimiter, enclosure, line ending, BOM) from `style.csv`. xlsx: non-numeric cells are inert strings.
- Lazy loading is blocked while exporting: the relations the resource touches must be in the filter's `$with` list (constrained loads such as `['lines' => fn ($q) => ...]` are accepted).

## Performance and sizing
Measured earlier (Postgres 16): csv 500k rows in 54s at 48MB peak, xlsx 500k rows in 55s at 48MB peak.

## Development
```
composer check      # pint --test, phpstan (level 5)
vendor/bin/pint     # fix style
```
