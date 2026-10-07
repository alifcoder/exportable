<?php

declare(strict_types=1);

namespace Alif\Export\Services\Actions\Export;

use Alif\Export\Contracts\ExportAuth;
use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\Entities\DataExport;
use Alif\Export\Enums\ExportStatus;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportRegistry;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

/** Starts a new export with the options of a failed one, if its definition still allows them. */
final readonly class RetryExport
{
    public function __construct(
        private ExportRegistry $registry,
        private ExportAuth $auth,
        private StartExport $start,
    ) {}

    /**
     * @throws ExportException Not failed, owner lost the permission, or over quota.
     * @throws ValidationException When the definition no longer has the chosen columns.
     */
    public function __invoke(Authenticatable $owner, DataExport $failed): DataExport
    {
        if ($failed->status !== ExportStatus::FAILED) {
            throw ExportException::notFailed();
        }

        $dto = ExportCreateDTO::fromArray($failed->options);

        if (! $this->auth->allows($owner, $dto->exportable)) {
            throw ExportException::forbidden();
        }

        $definition = $this->registry->has($dto->exportable) ? $this->registry->get($dto->exportable) : null;
        $changed = $definition === null
            || array_diff($dto->columns, array_keys($definition->columns())) !== []
            || array_diff($dto->childColumns, array_keys($definition->childColumns())) !== []
            || ($dto->includeChildren && $definition->childRelation() === null);

        if ($changed) {
            throw ValidationException::withMessages(['exportable' => ['The export definition has changed; create a new export.']]);
        }

        return ($this->start)($owner, $dto);
    }
}
