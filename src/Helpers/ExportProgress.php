<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Alif\Export\Enums\ExportPhaseEnum;
use Alif\Export\Events\ExportProgressed;
use Generator;
use Throwable;

/**
 * Sends `ExportProgressed` for one export, whatever its size: on every phase change, and while documents are read at
 * most once per `progress.throttle_ms`. `percent` is null when the total is unknown, never goes down and is 100 only
 * in {@see finish()}. A listener that fails never fails the export.
 */
final class ExportProgress
{
    private ExportPhaseEnum $phase = ExportPhaseEnum::QUEUED;

    private int $rows = 0;

    private ?int $percent;

    private float $sentAt = 0.0;

    public function __construct(private readonly string $exportId, private readonly ?int $totalRows)
    {
        $this->percent = $this->hasTotal() ? 0 : null;
    }

    public function phase(ExportPhaseEnum $phase): void
    {
        $this->phase = $phase;
        $this->send();
    }

    /**
     * Passes the documents through and counts them.
     *
     * @template TDocument
     *
     * @param  iterable<int, TDocument>  $documents
     * @return Generator<int, TDocument>
     */
    public function track(iterable $documents): Generator
    {
        foreach ($documents as $document) {
            if ($this->rows === 0) {
                $this->phase(ExportPhaseEnum::WRITING);
            }

            yield $document;

            $this->rows++;

            if ($this->hasTotal()) {
                // Held below 100 until finish().
                $this->percent = min(99, intdiv($this->rows * 100, (int) $this->totalRows));
            }

            if ((microtime(true) - $this->sentAt) * 1000 >= ExportConfig::int('progress.throttle_ms')) {
                $this->send();
            }
        }
    }

    /** The last event, sent right before `ExportFinished`. */
    public function finish(): void
    {
        $this->percent = 100;
        $this->send();
    }

    private function hasTotal(): bool
    {
        return $this->totalRows !== null && $this->totalRows > 0;
    }

    private function send(): void
    {
        $this->sentAt = microtime(true);

        if (! ExportConfig::bool('progress.enabled')) {
            return;
        }

        try {
            ExportProgressed::dispatch($this->exportId, $this->phase, $this->percent, $this->rows, $this->totalRows);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
