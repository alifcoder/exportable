# Changelog

## 0.1.0

- Async document export (csv, xlsx, pdf) on `alifcoder/query-filter`.
- Endpoints: definition, create, show, download, list, delete/cancel, retry.
- `ExportFinished` event for host notifications.
- Row cap counts flattened output rows at submit (422) and while streaming.
- Download re-checks the permission.
- CI, Pint and PHPStan (level 5) quality gate; opt-in stress test (`--group stress`).
