<?php

declare(strict_types=1);

namespace Alif\Export\Http\Requests\Export;

use Alif\Export\Contracts\Exportable;
use Alif\Export\Contracts\ExportAuth;
use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\Enums\ExportFormat;
use Alif\Export\Helpers\ExportRegistry;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * `data` is validated by the host filter (fields, operators, operands), not here. A host that enables
 * FormRequest::failOnUnknownFields() globally must not reject those nested keys.
 */
#[FailOnUnknownFields(false)]
final class ExportCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $key = $this->input('exportable');
        $user = $this->user();

        if (! is_string($key) || ! app(ExportRegistry::class)->has($key)) {
            return true; // Reported as a 422 on "exportable" by rules().
        }

        return $user instanceof Authenticatable && app(ExportAuth::class)->allows($user, $key);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $exportable = $this->resolveExportable();
        $columns = $exportable === null ? [] : array_keys($exportable->columns());
        $childColumns = $exportable === null ? [] : array_keys($exportable->childColumns());
        $hasChildren = $exportable?->childRelation() !== null;
        $childrenOn = $hasChildren && $this->boolean('file.include_children');

        return [
            'exportable' => ['required', 'string', 'max:100', fn (string $attribute, mixed $value, \Closure $fail) => is_string($value) && app(ExportRegistry::class)->has($value) ? null : $fail('The selected exportable is invalid.')],
            'data' => ['sometimes', 'nullable', 'array'],
            'file' => ['required', 'array'],
            'file.format' => ['required', Rule::enum(ExportFormat::class)],
            'file.columns' => ['required', 'array', 'min:1'],
            'file.columns.*' => ['required', 'string', 'distinct', Rule::in($columns)],
            'file.include_children' => ['sometimes', 'boolean'],
            'file.child_columns' => $childrenOn ? ['required', 'array', 'min:1'] : ['prohibited'],
            'file.child_columns.*' => ['string', 'distinct', Rule::in($childColumns)],
            'file.title' => ['nullable', 'string', 'max:150'],
        ];
    }

    public function exportableKey(): string
    {
        return (string) $this->input('exportable');
    }

    public function getDto(): ExportCreateDTO
    {
        $file = (array) $this->input('file');
        $includeChildren = (bool) ($file['include_children'] ?? false);

        return new ExportCreateDTO(
            exportable: $this->exportableKey(),
            format: ExportFormat::from($file['format']),
            columns: array_values($file['columns']),
            includeChildren: $includeChildren,
            childColumns: $includeChildren ? array_values($file['child_columns'] ?? []) : [],
            title: $file['title'] ?? null,
            parameters: Arr::only((array) $this->input('data', []), (array) config('export.data_parameters', [])),
        );
    }

    private function resolveExportable(): ?Exportable
    {
        $key = $this->input('exportable');
        $registry = app(ExportRegistry::class);

        return is_string($key) && $registry->has($key) ? $registry->get($key) : null;
    }
}
