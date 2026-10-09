<?php

declare(strict_types=1);

namespace Alif\Export\Services\Actions\Export;

use Alif\Export\Contracts\ExportAuth;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportBuilder;
use Alif\Export\Helpers\ExportPlan;
use Alif\Export\Helpers\ExportProgress;
use Alif\Export\Helpers\ExportRegistry;
use Alif\Export\Helpers\ExportWriter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;

/** Writes the file of a task, signed in as its owner so the host filter's scope applies. */
final readonly class WriteExport
{
    public function __construct(
        private ExportAuth $auth,
        private ExportRegistry $registry,
        private ExportBuilder $builder,
        private ExportWriter $writer,
        private ExportProgress $progress,
    ) {}

    /**
     * @param  string  $path  Local file to write.
     * @return int Rows written.
     *
     * @throws ExportException
     */
    public function __invoke(ExportTask $task, string $path): int
    {
        $previousLocale = App::getLocale();
        App::setLocale($task->locale);

        try {
            return $this->auth->actingAs(
                $task->ownerId,
                fn (Authenticatable $owner): int => $this->write($task, $owner, $path),
            );
        } finally {
            App::setLocale($previousLocale);
        }
    }

    private function write(ExportTask $task, Authenticatable $owner, string $path): int
    {
        $dto = $task->request;

        if (! $this->auth->allows($owner, $dto->exportable)) {
            throw ExportException::forbidden();
        }

        $exportable = $this->registry->get($dto->exportable);
        $plan = ExportPlan::for($exportable, $dto, lenient: true);
        $previous = Model::preventsLazyLoading();
        Model::preventLazyLoading();

        try {
            return $this->writer->store(
                $dto->format,
                $dto->title ?? (string) __($exportable->title()),
                $plan->headings(),
                $plan->numeric(),
                $this->progress->track($task, $this->builder->rows($plan, $this->builder->query($plan))),
                $path,
            );
        } finally {
            Model::preventLazyLoading($previous);
        }
    }
}
