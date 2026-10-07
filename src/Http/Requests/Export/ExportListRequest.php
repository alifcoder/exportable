<?php

declare(strict_types=1);

namespace Alif\Export\Http\Requests\Export;

use Alif\Export\DTO\Export\ExportListDTO;
use Alif\Export\Entities\DataExport;
use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** `page` and any other pagination key the host adds are read by the paginator, not validated here. */
#[FailOnUnknownFields(false)]
final class ExportListRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in([
                DataExport::STATUS_PENDING, DataExport::STATUS_PROCESSING, DataExport::STATUS_COMPLETED, DataExport::STATUS_FAILED,
            ])],
            'exportable' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function getDto(): ExportListDTO
    {
        return new ExportListDTO(
            ownerId: (string) $this->user()->getAuthIdentifier(),
            status: $this->validated('status'),
            exportable: $this->validated('exportable'),
            perPage: (int) $this->validated('per_page', 20),
        );
    }
}
