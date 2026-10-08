<?php

declare(strict_types=1);

namespace Alif\Export\Http\Controllers;

use Alif\Export\Http\Requests\Export\ExportCreateRequest;
use Alif\Export\Http\Requests\Export\ExportListRequest;
use Alif\Export\Http\Requests\Export\ExportOwnerRequest;
use Alif\Export\Services\Interfaces\ExportServiceInterface;
use Alif\Export\Transformers\Export\ExportDefinitionResource;
use Alif\Export\Transformers\Export\ExportResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportController extends Controller
{
    public function __construct(private readonly ExportServiceInterface $exportService) {}

    public function definition(ExportOwnerRequest $request): ExportDefinitionResource
    {
        $key = (string) $request->query('exportable');

        return new ExportDefinitionResource($this->exportService->definition($request->getOwner(), $key), $key);
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

    public function show(ExportOwnerRequest $request, string $export): ExportResource
    {
        return new ExportResource($this->exportService->find($request->getOwner(), $export));
    }

    public function download(ExportOwnerRequest $request, string $export): StreamedResponse
    {
        $file = $this->exportService->download($request->getOwner(), $export);

        return Storage::disk($file->disk)->download($file->path, $file->fileName, ['Content-Type' => $file->mimeType]);
    }

    public function destroy(ExportOwnerRequest $request, string $export): Response
    {
        $this->exportService->delete($request->getOwner(), $export);

        return response()->noContent();
    }

    public function retry(ExportOwnerRequest $request, string $export): JsonResponse
    {
        $retried = $this->exportService->retry($request->getOwner(), $export);

        return (new ExportResource($retried))->response()->setStatusCode(202);
    }
}
