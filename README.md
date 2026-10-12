# alifcoder/exportable

Async document export (csv, excel) for Laravel, built on `alifcoder/query-filter` (`^2.0.1`, enforced by Composer).

The package is host-driven and **stateless**: it ships **no routes, no controllers, no database table, no schedule, no config defaults and no file storage**. The host provides the configuration, the endpoints, the permission check, the place files are kept and the list of finished exports.

## Flow
The host's filter uses `Concerns\HandlesExport`; its endpoint calls `$filter->export()` after authorising the user. On that request, and before any job exists, the package: validates `format` and `columns` and the filter's own parameters, applies the filter (so the caller's data limits are in the query), checks every requested column against what the filter's resource yields for a sample of rows (and reads each column's type from it), counts the output rows once (cap, empty check and progress total), takes a quota slot (cache) and compiles the query into SQL + bindings. It then queues `RunExport` with an `ExportTask` (id, owner id, locale, the prepared query, columns, row total).

The job needs no signed-in user and does not rebuild the filter: it reads the prepared SQL page by page (`chunk_size` rows at a time, `limit/offset` after the stable order), hydrates the models, eager loads the filter's `$with` relations, renders each model through the resource, flattens children, writes a **local temporary file**, hands it to the host's `ExportFileStore`, removes the temporary file, frees the quota slot and dispatches `ExportFinished` (completed or failed, with an error code). While it runs, `ExportProgressed` events tell the owner's client how far it is. Nothing is persisted by the package; the host keeps whatever it needs from `ExportFinished`.

Paging is by offset over a query ordered by the filter's sort and then the key, so rows inserted or deleted *while the job runs* can shift a page. Postgres, MySQL/MariaDB and SQLite are supported.

## Install
1. `composer require alifcoder/exportable`
2. `php artisan vendor:publish --tag=export-config`, set **every** key in `config/export.php` (a missing one fails with `Missing export configuration "export.<key>"`).
3. Bind `Contracts\ExportFileStore`: `put(ExportTask, localPath, fileName, mime): string` (returns your file id). Links, listing and deletion are the host's. There is no auth contract and no gate/ability/guard setting.
4. Use `Concerns\HandlesExport` in your filter base class and implement `Contracts\ExportSource` (the trait provides `exportSource()`). The filter must provide `static $model`, `static $resource`, `givenParameters()`, `prepare()`, `apply()` and `getWith()`. Optional hooks: `exportQuery()` (start from the filter's own selects/counts/ordering instead of the bare model) and `exportWindow($query)` (limit to the page the list shows). Write the endpoint, authorise the user there, call `$filter->export()`, and listen to `ExportFinished`.
5. Run a queue worker for `queue.name` with enough memory/timeout for xlsx. The queue connection's `retry_after` must exceed `queue.timeout`, otherwise a second worker picks up the running job. The quota needs a cache store with atomic locks.
6. Authorize the private channel `App.Models.User.{id}` (Laravel's default user channel) if you use progress events.
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
- `columns`: resource key (dotted for nested objects) => heading. One entry may hold an object: the name of a HasMany relation => its own key => heading map; that relation's rows are written one per line next to the document's columns (a document without children gets one line with blank child cells). At most 200 columns, headings 1 to 150 characters. The file name is derived from the filter class (`SaleFilter` => `sale_<timestamp>.xlsx`).

Errors raised on the request: `ValidationException` (422) for the export rules, the filter's parameters, an unknown column (`columns`), an empty result and the row cap (`format`); `ExportException` `too_many_active` (429).

## Config (all required)
| Key | Meaning |
|---|---|
| `ttl_hours` | hours a finished export stays downloadable |
| `chunk_size`, `max_rows.{csv,xlsx}`, `max_active_per_user` | paging, caps, quota |
| `queue.{connection,name,timeout}` | `connection`/`name` may be `null` but must be present |
| `stale_margin_seconds` | a quota slot never released (worker killed) expires `queue.timeout` + this many seconds after it was taken |
| `events.finished` | dispatch `ExportFinished` when an export completes or fails; `false` silences it |
| `progress.{enabled,min_rows,step_percent}` | broadcast `ExportProgressed` for exports of at least `min_rows` rows, at most every `step_percent` points, and a final 100 once the last row is written (the finished event then announces the file) |
| `style.{date_format,datetime_format,time_format}` | PHP `date()` formats of dates in csv |
| `style.boolean.{true,false}` | text of boolean cells in csv and xlsx; a translation key is translated |
| `style.csv.{delimiter,enclosure,line_ending,bom}` | csv field separator and quote (single byte), row ending, UTF-8 byte order mark |
| `style.xlsx.font`, `.header`, `.column_width` | font, header look (ARGB colours), width = heading length + padding clamped to min..max |
| `style.xlsx.{integer_format,number_format,percent_format,currency_format}` | Excel number formats of whole, decimal, percent and currency cells |
| `style.xlsx.{date_format,datetime_format,time_format}` | Excel formats (Excel syntax, e.g. `yyyy-mm-dd`) of date cells |

## Column types
A cell is written as its column's type, set with `Column::as(ColumnType::…)` (`numeric()` and `date()` are shortcuts) or, for `ResourceExportable`, the `types` map (`['discount' => ColumnType::Percent]`). A column without a type is read from each value, and `ResourceExportable` also recognises, from the first value it samples, the date (`2026-03-04`) and date-time (`2026-03-04T05:06:07Z`) text a resource renders, and a decimal numeric string by its key (`*_amount`, `*_price`, ...).

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
- `ExportProgressed` (`ShouldBroadcastNow`, private channel `App.Models.User.{ownerId}`, `export.progressed`): `id`, `percent`, `rows`, `total_rows`.

## Layout and safety
- Children are flattened: one row per child with document columns repeated; a document without children yields one row with blank child cells. Output order: `columns` then `child_columns`.
- Caps count output rows. csv: formula-prefix guard on non-numeric columns; layout (delimiter, enclosure, line ending, BOM) from `style.csv`. xlsx: non-numeric cells are inert strings.
- Lazy loading is blocked while exporting: columns declare the relations they need (`Column::make(...)->relations('customer')`; constrained loads such as `['lines' => fn ($q) => ...]` are accepted); `ResourceExportable` takes them from the host's `$with` list.

## Performance and sizing
Measured earlier (2 child lines per document, Postgres 16): csv 500k rows in 54s at 48MB peak, xlsx 500k rows in 55s at 48MB peak. Index the child foreign key.

## Development
```
composer check      # pint --test, phpstan (level 5), phpunit
vendor/bin/pint     # fix style
```
The tests bind the file store and a fixture filter themselves (`tests/Fixtures`), exactly as a host would.
