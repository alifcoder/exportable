<?php

declare(strict_types=1);

namespace Alif\Export\Services\Actions\Export;

use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ColumnNode;
use Alif\Export\Helpers\ExportBuilder;
use Alif\Export\Helpers\ExportProgress;
use Alif\Export\Helpers\Writers\Line;
use Generator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;

/** Writes the file of a task from its prepared query. It needs no signed-in user: the access limits are already in the SQL. */
final readonly class WriteExport
{
    public function __construct(
        private ExportBuilder $builder,
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
        $tree = ColumnNode::tree($dto->columns);
        $previous = Model::preventsLazyLoading();
        Model::preventLazyLoading();

        try {
            return $dto->format->writer()->write(
                $tree->headings(),
                $tree->types(),
                $this->lines($this->progress->track($task, $this->builder->documents($dto))),
                $path,
                $tree->widths(),
            );
        } finally {
            Model::preventLazyLoading($previous);
        }
    }

    /**
     * Progress counts documents; the writer wants their lines one after the other.
     *
     * @param  iterable<int, list<Line>>  $documents
     * @return Generator<int, Line>
     */
    private function lines(iterable $documents): Generator
    {
        foreach ($documents as $lines) {
            yield from $lines;
        }
    }
}
