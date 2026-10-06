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
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
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

        abort_unless($model->status === DataExport::STATUS_COMPLETED, 409, 'Export is not ready.');
        abort_unless($model->isDownloadable(), 410, 'Export file is no longer available.');

        return Storage::disk((string) $model->disk)->download(
            (string) $model->path,
            $model->file_name,
            ['Content-Type' => $model->exportOptions()->format->mimeType()],
        );
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
