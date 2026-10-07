<?php

declare(strict_types=1);

namespace Alif\Export\Http;

use Alif\Export\Models\DataExport;
use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** `page` and any other pagination key the host adds are read by the paginator, not validated here. */
#[FailOnUnknownFields(false)]
final class ListExportsRequest extends FormRequest
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
}
