<?php

declare(strict_types=1);

namespace Alif\Export\Services\Actions\Export;

use Alif\Export\DTO\Export\ExportColumn;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportBuilder;
use Alif\Export\Helpers\ExportProgress;
use Alif\Export\Helpers\ExportWriter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;

/** Writes the file of a task from its prepared query. It needs no signed-in user: the access limits are already in the SQL. */
final readonly class WriteExport
{
    public function __construct(
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
        $columns = $dto->columns;
        $previous = Model::preventsLazyLoading();
        Model::preventLazyLoading();

        try {
            return $this->writer->store(
                $dto->format,
                array_map(fn (ExportColumn $column): string => $column->label, $columns),
                array_map(fn (ExportColumn $column) => $column->type, $columns),
                $this->progress->track($task, $this->builder->rows($dto)),
                $path,
            );
        } finally {
            Model::preventLazyLoading($previous);
        }
    }
}
