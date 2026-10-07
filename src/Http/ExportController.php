<?php

declare(strict_types=1);

namespace Alif\Export\Http;

use Alif\Export\Actions\StartExport;
use Alif\Export\Column;
use Alif\Export\Contracts\ExportAuth;
use Alif\Export\Enums\ExportFormat;
use Alif\Export\ExportRegistry;
use Alif\Export\Models\DataExport;
use Alif\Export\Support\QueryFilterCompatibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportController extends Controller
{
    public function __construct(
        private readonly ExportRegistry $registry,
        private readonly ExportAuth $auth,
    ) {}

    public function definition(Request $request, string $exportable): JsonResponse
    {
        QueryFilterCompatibility::assertCompatible();

        abort_unless($this->registry->has($exportable), 404);
        abort_unless($request->user() !== null && $this->auth->allows($request->user(), $exportable), 403);

        $definition = $this->registry->get($exportable);
        $labels = fn (array $columns): array => collect($columns)
            ->map(fn (Column $column, string $key): array => ['key' => $key, 'label' => (string) __($column->label())])
            ->values()
            ->all();

        return response()->json(['data' => [
            'key' => $exportable,
            'title' => (string) __($definition->title()),
            'formats' => collect(ExportFormat::cases())->mapWithKeys(fn (ExportFormat $f): array => [$f->value => $f->maxRows()])->all(),
            'columns' => $labels($definition->columns()),
            'child_columns' => $labels($definition->childColumns()),
        ]]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        QueryFilterCompatibility::assertCompatible();

        $user = $request->user();
        abort_if($user === null, 401);

        $filters = $request->validate([
            'status' => ['sometimes', 'string', Rule::in([
                DataExport::STATUS_PENDING, DataExport::STATUS_PROCESSING, DataExport::STATUS_COMPLETED, DataExport::STATUS_FAILED,
            ])],
            'exportable' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return ExportResource::collection(
            DataExport::query()
                ->where('owner_id', (string) $user->getAuthIdentifier())
                ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
                ->when(isset($filters['exportable']), fn ($q) => $q->where('exportable', $filters['exportable']))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->simplePaginate((int) ($filters['per_page'] ?? 20)),
        );
    }

    public function store(StoreExportRequest $request, StartExport $action): JsonResponse
    {
        QueryFilterCompatibility::assertCompatible();

        $export = $action->handle($request->user(), $request->toOptions());

        return (new ExportResource($export))->response()->setStatusCode(202);
    }

    public function show(Request $request, string $export): ExportResource
    {
        QueryFilterCompatibility::assertCompatible();

        return new ExportResource($this->ownedExport($request, $export));
    }

    public function download(Request $request, string $export): StreamedResponse
    {
        QueryFilterCompatibility::assertCompatible();

        $model = $this->ownedExport($request, $export);

        abort_unless($this->auth->allows($request->user(), $model->exportable), 403);
        abort_unless($model->status === DataExport::STATUS_COMPLETED, 409, 'Export is not ready.');
        abort_unless($model->isDownloadable(), 410, 'Export file is no longer available.');

        return Storage::disk((string) $model->disk)->download(
            (string) $model->path,
            $model->file_name,
            ['Content-Type' => $model->exportOptions()->format->mimeType()],
        );
    }

    /** Cancel a pending export or delete a finished one together with its file. A live processing export is 409. */
    public function destroy(Request $request, string $export): Response
    {
        QueryFilterCompatibility::assertCompatible();

        $model = $this->ownedExport($request, $export);

        // Atomic: a worker that claims the row between the check and the delete makes this a 409, never an orphan.
        $deleted = DataExport::query()
            ->whereKey($model->getKey())
            ->where(fn ($q) => $q
                ->whereIn('status', [DataExport::STATUS_PENDING, DataExport::STATUS_COMPLETED, DataExport::STATUS_FAILED])
                ->orWhere(fn ($s) => $s->stale()))
            ->delete();

        abort_if($deleted === 0, 409, 'Export is being processed.');

        if ($model->disk !== null && $model->path !== null) {
            Storage::disk($model->disk)->delete($model->path);
        }

        return response()->noContent();
    }

    /** Start a new export with the options of a failed one. */
    public function retry(Request $request, StartExport $action, string $export): JsonResponse
    {
        QueryFilterCompatibility::assertCompatible();

        $model = $this->ownedExport($request, $export);

        abort_unless($model->status === DataExport::STATUS_FAILED, 409, 'Only failed exports can be retried.');

        $options = $model->exportOptions();
        $user = $request->user();

        abort_unless($this->auth->allows($user, $options->exportable), 403);

        $definition = $this->registry->has($options->exportable) ? $this->registry->get($options->exportable) : null;
        $stale = $definition === null
            || array_diff($options->columns, array_keys($definition->columns())) !== []
            || array_diff($options->childColumns, array_keys($definition->childColumns())) !== []
            || ($options->includeChildren && $definition->childRelation() === null);

        if ($stale) {
            throw ValidationException::withMessages(['exportable' => ['The export definition has changed; create a new export.']]);
        }

        return (new ExportResource($action->handle($user, $options)))->response()->setStatusCode(202);
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
