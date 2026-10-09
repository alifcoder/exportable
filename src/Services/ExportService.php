<?php

declare(strict_types=1);

namespace Alif\Export\Services;

use Alif\Export\Contracts\Exportable;
use Alif\Export\Contracts\ExportAuth;
use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportRegistry;
use Alif\Export\Services\Actions\Export\StartExport;
use Alif\Export\Services\Interfaces\ExportServiceInterface;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class ExportService implements ExportServiceInterface
{
    public function __construct(
        private ExportRegistry $registry,
        private ExportAuth $auth,
        private StartExport $startExport,
    ) {}

    public function definition(Authenticatable $user, string $key): Exportable
    {
        if (! $this->registry->has($key) || ! $this->auth->allows($user, $key)) {
            throw ExportException::forbidden();
        }

        return $this->registry->get($key);
    }

    public function create(Authenticatable $owner, ExportCreateDTO $dto): ExportTask
    {
        return ($this->startExport)($owner, $dto);
    }
}
