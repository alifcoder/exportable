<?php

declare(strict_types=1);

namespace Alif\Export\Http\Controllers;

use Alif\Export\Exceptions\ExportException;
use Alif\Export\Http\Requests\Export\ExportCreateRequest;
use Alif\Export\Http\Requests\Export\ExportListRequest;
use Alif\Export\Services\Interfaces\ExportServiceInterface;
use Alif\Export\Transformers\Export\ExportDefinitionResource;
use Alif\Export\Transformers\Export\ExportResource;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportController extends Controller
{
    public function __construct(private readonly ExportServiceInterface $exportService) {}

    public function definition(Request $request): ExportDefinitionResource
    {
        $key = (string) $request->query('exportable');
        $user = $request->user() ?? throw ExportException::forbidden();

        return new ExportDefinitionResource($this->exportService->definition($user, $key), $key);
    }

    public function index(ExportListRequest $request): AnonymousResourceCollection
    {
        return ExportResource::collection($this->exportService->all($request->getDto()));
    }

    public function store(ExportCreateRequest $request): JsonResponse
    {
        $export = $this->exportService->create($request->user(), $request->getDto());

        return (new ExportResource($export))->response()->setStatusCode(202);
    }

    public function show(Request $request, string $export): ExportResource
    {
        return new ExportResource($this->exportService->findOwned($this->owner($request), $export));
    }

    public function download(Request $request, string $export): StreamedResponse
    {
        $owner = $this->owner($request);
        $model = $this->exportService->findOwned($owner, $export);
        $this->exportService->assertDownloadable($owner, $model);

        return Storage::disk((string) $model->disk)->download(
            (string) $model->path,
            $model->file_name,
            ['Content-Type' => $model->exportDto()->format->mimeType()],
        );
    }

    public function destroy(Request $request, string $export): Response
    {
        $this->exportService->delete($this->exportService->findOwned($this->owner($request), $export));

        return response()->noContent();
    }

    public function retry(Request $request, string $export): JsonResponse
    {
        $owner = $this->owner($request);
        $new = $this->exportService->retry($owner, $this->exportService->findOwned($owner, $export));

        return (new ExportResource($new))->response()->setStatusCode(202);
    }

    private function owner(Request $request): Authenticatable
    {
        return $request->user() ?? throw ExportException::notFound();
    }
}
