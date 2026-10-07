<?php

declare(strict_types=1);

namespace Alif\Export\Http\Requests\Export;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An unknown, missing or forbidden `exportable` answers alike (403) in the service, so the endpoint does not reveal
 * which keys exist: the key is therefore not validated here.
 */
#[FailOnUnknownFields(false)]
final class ExportDefinitionRequest extends FormRequest
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

    public function getExportableKey(): string
    {
        return (string) $this->query('exportable');
    }
}
