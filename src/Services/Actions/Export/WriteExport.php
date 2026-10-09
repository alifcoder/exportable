<?php

declare(strict_types=1);

namespace Alif\Export\Services\Actions\Export;

use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportBuilder;
use Alif\Export\Helpers\ExportPlan;
use Alif\Export\Helpers\ExportProgress;
use Alif\Export\Helpers\ExportRegistry;
use Alif\Export\Helpers\ExportWriter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;

/** Writes the file of a task. Who the job runs as, and whether they may still export, is the host's job middleware. */
final readonly class WriteExport
{
    public function __construct(
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
            return $this->write($task, $path);
        } finally {
            App::setLocale($previousLocale);
        }
    }

    private function write(ExportTask $task, string $path): int
    {
        $dto = $task->request;
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
