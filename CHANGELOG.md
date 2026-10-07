# Changelog

## Unreleased

- Breaking: restructured into layers following erp-backend. Namespaces moved: `Column`, `ExportRegistry`, `ExportBuilder`, `ExportPlan`, `ExportWriter`, `Writers\*` → `Helpers\*`; `Models\DataExport` → `Entities\DataExport`; `ExportException` → `Exceptions\ExportException`; `ExportOptions` → `DTO\Export\ExportCreateDTO`; `ExportServiceProvider` → `Providers\ExportServiceProvider`; `Http\ExportResource` → `Transformers\Export\ExportResource`; `Actions\*` → `Services\Actions\Export\*` (invokable: `$action(...)` instead of `->handle(...)`). `DataExport::exportOptions()` is now `exportDto()`. `ExportFormat` cases are now `CSV` / `XLSX`. Hosts that only register exportables, `Exportable` / `ExportAuth` implementations and `ExportFinished` listeners must update the `Column`, `ExportRegistry` and `DataExport` imports.
- Controller → `ExportServiceInterface` (bound in the provider) → Actions. Services throw `ExportException` (403/404/409/410/429 via `httpStatus()`), not HTTP aborts; HTTP responses and status codes are unchanged.
- Breaking: the `pdf` format is removed (csv and xlsx only), together with the `barryvdh/laravel-dompdf` dependency, the `export::pdf` view and `export.max_rows.pdf`. The migration stub's `format` check is now `('csv','xlsx')`; an existing Postgres table keeps the old constraint, which is harmless. Old rows with format `pdf` can no longer be created, so delete them.
- Fix: a storage disk that returns `false` on write (no `throw`) no longer yields a `completed` export without a file; it fails with `storage_write_failed`.
- Fix: the submit-time row count uses the exportable model's connection, not the default one.
- Fix: rows are read from a key snapshot in key chunks instead of offset paging, so data changing during a long export cannot skip or repeat rows (the filter's sort is kept).
- Fix: key-chunked reading ignores the filter's limit/offset per chunk (applied once to the snapshot) and collapses duplicate keys from joins.
- Fix: `XlsxWriter` fails with `storage_write_failed` if the temp file cannot be reopened; `storage_write_failed` is reported to the exception handler.
- Breaking: `definition` answers 403 for an unknown key (was 404), so key existence is not revealed.
- Breaking: `export.prohibited_parameters` defaults to `[]` (was the host-specific `['all', 'pos_auth_id']`).
- Docs: drop the stale `incompatible_query_filter` troubleshooting entry.

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

## 0.1.0

- Async document export (csv, xlsx, pdf) on `alifcoder/query-filter`.
- Endpoints: definition, create, show, download, list, delete/cancel, retry.
- `ExportFinished` event for host notifications.
- Row cap counts flattened output rows at submit (422) and while streaming.
- Download re-checks the permission.
- CI, Pint and PHPStan (level 5) quality gate; opt-in stress test (`--group stress`).
