<?php

declare(strict_types=1);

namespace Alif\Export\Actions;

use Alif\Export\Contracts\ExportAuth;
use Alif\Export\ExportRegistry;
use Alif\Export\Models\DataExport;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Starts a new export with the options of a failed one, if its definition still allows them. */
final class RetryExport
{
    public function __construct(
        private readonly ExportRegistry $registry,
        private readonly ExportAuth $auth,
        private readonly StartExport $start,
    ) {}

    /**
     * @throws HttpException 409 when the export did not fail, 403 when the owner lost the permission, 429 over quota.
     * @throws ValidationException When the definition no longer has the chosen columns.
     */
    public function handle(Authenticatable $owner, DataExport $failed): DataExport
    {
        abort_unless($failed->status === DataExport::STATUS_FAILED, 409, 'Only failed exports can be retried.');

        $options = $failed->exportOptions();

        abort_unless($this->auth->allows($owner, $options->exportable), 403);

        $definition = $this->registry->has($options->exportable) ? $this->registry->get($options->exportable) : null;
        $changed = $definition === null
            || array_diff($options->columns, array_keys($definition->columns())) !== []
            || array_diff($options->childColumns, array_keys($definition->childColumns())) !== []
            || ($options->includeChildren && $definition->childRelation() === null);

        if ($changed) {
            throw ValidationException::withMessages(['exportable' => ['The export definition has changed; create a new export.']]);
        }

        return $this->start->handle($owner, $options);
    }
}
