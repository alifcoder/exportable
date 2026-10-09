<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Events\ExportProgressed;
use Generator;
use Throwable;

/**
 * Passes rows through and reports the percentage of `total_rows` written, at most once per
 * `export.progress.step_percent`, and 100 when the last row is written. Inactive (a plain pass-through) when progress is off or the export is smaller
 * than `export.progress.min_rows`. A listener that fails never fails the export.
 */
final class ExportProgress
{
    /**
     * @template TRow
     *
     * @param  iterable<int, TRow>  $rows
     * @return Generator<int, TRow>
     */
    public function track(ExportTask $task, iterable $rows): Generator
    {
        $total = (int) $task->totalRows;
        $active = ExportConfig::bool('progress.enabled') && $total > 0 && $total >= ExportConfig::int('progress.min_rows');
        $step = max(1, ExportConfig::int('progress.step_percent'));
        $sent = 0;
        $emitted = 0;

        foreach ($rows as $row) {
            yield $row;

            if (! $active) {
                continue;
            }

            $emitted++;
            // Held below 100 while rows are written; 100 is sent once the last row is out.
            $percent = min(99, intdiv($emitted * 100, $total));

            if ($percent >= $sent + $step) {
                $sent = $percent;
                $this->send($task, $percent, $emitted, $total);
            }
        }

        if ($active) {
            $this->send($task, 100, $emitted, $total);
        }
    }

    private function send(ExportTask $task, int $percent, int $rows, int $total): void
    {
        try {
            ExportProgressed::dispatch($task->id, $task->ownerId, $percent, $rows, $total);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
