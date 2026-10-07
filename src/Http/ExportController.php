<?php

declare(strict_types=1);

namespace Alif\Export\Http;

use Alif\Export\Actions\RetryExport;
use Alif\Export\Actions\StartExport;
use Alif\Export\Column;
use Alif\Export\Contracts\ExportAuth;
use Alif\Export\Enums\ExportFormat;
use Alif\Export\ExportRegistry;
use Alif\Export\Models\DataExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportController extends Controller
{
    public function __construct(
        private readonly ExportRegistry $registry,
        private readonly ExportAuth $auth,
    ) {}

    public function definition(Request $request): JsonResponse
    {
        $key = (string) $request->query('exportable');

        // Unknown and forbidden answer alike, so the endpoint does not reveal which keys exist.
        abort_unless(
            $request->user() !== null && $this->registry->has($key) && $this->auth->allows($request->user(), $key),
            403,
        );

        $definition = $this->registry->get($key);
        $labels = fn (array $columns): array => collect($columns)
            ->map(fn (Column $column, string $columnKey): array => ['key' => $columnKey, 'label' => (string) __($column->label())])
            ->values()
            ->all();

        return response()->json(['data' => [
            'key' => $key,
            'title' => (string) __($definition->title()),
            'formats' => collect(ExportFormat::cases())->mapWithKeys(fn (ExportFormat $f): array => [$f->value => $f->maxRows()])->all(),
            'columns' => $labels($definition->columns()),
            'child_columns' => $labels($definition->childColumns()),
        ]]);
    }

    public function index(ListExportsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        return ExportResource::collection(
            DataExport::query()
                ->where('owner_id', (string) $request->user()->getAuthIdentifier())
                ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
                ->when(isset($filters['exportable']), fn ($q) => $q->where('exportable', $filters['exportable']))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->simplePaginate((int) ($filters['per_page'] ?? 20)),
        );
    }

    public function store(StoreExportRequest $request, StartExport $action): JsonResponse
    {
        return (new ExportResource($action->handle($request->user(), $request->toOptions())))->response()->setStatusCode(202);
    }

    public function show(Request $request, string $export): ExportResource
    {
        return new ExportResource($this->ownedExport($request, $export));
    }

    public function download(Request $request, string $export): StreamedResponse
    {
        $model = $this->ownedExport($request, $export);

        abort_unless($this->auth->allows($request->user(), $model->exportable), 403);
        abort_unless($model->status === DataExport::STATUS_COMPLETED, 409, 'Export is not ready.');
        abort_unless($model->isDownloadable() && $model->fileExists(), 410, 'Export file is no longer available.');

        return Storage::disk((string) $model->disk)->download(
            (string) $model->path,
            $model->file_name,
            ['Content-Type' => $model->exportOptions()->format->mimeType()],
        );
    }

    /** Cancel a pending export or delete a finished one together with its file. A live processing export is 409. */
    public function destroy(Request $request, string $export): Response
    {
        $model = $this->ownedExport($request, $export);

        abort_unless($model->deleteIfIdle(), 409, 'Export is being processed.');

        if ($model->disk !== null && $model->path !== null) {
            Storage::disk($model->disk)->delete($model->path);
        }

        return response()->noContent();
    }

    public function retry(Request $request, RetryExport $action, string $export): JsonResponse
    {
        $new = $action->handle($request->user(), $this->ownedExport($request, $export));

        return (new ExportResource($new))->response()->setStatusCode(202);
    }

    private function ownedExport(Request $request, string $id): DataExport
    {
        $export = DataExport::query()->find($id);

        abort_if(
            $export === null || $request->user() === null || (string) $export->owner_id !== (string) $request->user()->getAuthIdentifier(),
            404,
        );

        return $export;
    }
}
