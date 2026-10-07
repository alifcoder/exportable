<?php

declare(strict_types=1);

namespace Alif\Export\Http\Requests\Export;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;
use Illuminate\Foundation\Http\FormRequest;

/** Guards the routes that act on one export of the caller: show, download, retry, delete. */
#[FailOnUnknownFields(false)]
final class ExportOwnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Authenticatable;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    public function getOwner(): Authenticatable
    {
        /** @var Authenticatable */
        return $this->user();
    }
}
