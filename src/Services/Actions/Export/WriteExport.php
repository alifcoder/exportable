<?php

declare(strict_types=1);

namespace Alif\Export\Services\Actions\Export;

use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ColumnNode;
use Alif\Export\Helpers\DocumentReader;
use Alif\Export\Helpers\ExportCancellation;
use Alif\Export\Helpers\ExportConfig;
use Alif\Export\Helpers\ExportProgress;
use Alif\Export\Helpers\Writers\Line;
use Generator;
use Illuminate\Database\Eloquent\Model;

/** Writes the file of a task from its prepared query. It needs no signed-in user: the access limits are already in the SQL. */
final readonly class WriteExport
{
    public function __construct(private DocumentReader $reader) {}

    /**
     * Writes the export file and returns how many rows were written. Lazy loading is blocked while it runs, to catch N+1
     * queries. A cancel is seen before every chunk.
     *
     * @param  string  $path  Local file to write.
     * @return int Rows written.
     *
     * @throws ExportException
     */
    public function __invoke(ExportTask $task, string $path, ExportProgress $progress): int
    {
        $dto = $task->request;
        $tree = ColumnNode::tree($dto->columns);
        $previous = Model::preventsLazyLoading();
        Model::preventLazyLoading();

        try {
            return $dto->format->writer()->write(
                $tree->headings(),
                $tree->types(),
                $this->lines($task->id, $progress->track($this->reader->documents($dto))),
                $path,
                $tree->widths(),
            );
        } finally {
            Model::preventLazyLoading($previous);
        }
    }

    /**
     * Progress counts documents; the writer wants their lines one after the other. After the last document of a chunk,
     * before the next chunk is loaded, the cancel flag is checked.
     *
     * @param  iterable<int, list<Line>>  $documents
     * @return Generator<int, Line>
     *
     * @throws ExportException cancelled
     */
    private function lines(string $exportId, iterable $documents): Generator
    {
        $chunk = max(1, ExportConfig::int('chunk_size'));
        $read = 0;

        foreach ($documents as $lines) {
            yield from $lines;

            if (++$read % $chunk === 0) {
                ExportCancellation::check($exportId);
            }
        }
    }
}
