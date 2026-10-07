<?php

declare(strict_types=1);

namespace Alif\Export\Actions;

use Alif\Export\Contracts\ExportAuth;
use Alif\Export\ExportBuilder;
use Alif\Export\ExportException;
use Alif\Export\ExportPlan;
use Alif\Export\ExportRegistry;
use Alif\Export\ExportWriter;
use Alif\Export\Models\DataExport;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;

/** Writes the file of a claimed export, signed in as its owner so the host filter's scope applies. */
final class GenerateExport
{
    public function __construct(
        private readonly ExportAuth $auth,
        private readonly ExportRegistry $registry,
        private readonly ExportBuilder $builder,
        private readonly ExportWriter $writer,
    ) {}

    /**
     * @return int Rows written.
     *
     * @throws ExportException
     */
    public function handle(DataExport $export): int
    {
        $previousLocale = App::getLocale();
        App::setLocale($export->locale);

        try {
            return $this->auth->actingAs(
                $export->owner_id,
                fn (Authenticatable $owner): int => $this->write($export, $owner),
            );
        } finally {
            App::setLocale($previousLocale);
        }
    }

    private function write(DataExport $export, Authenticatable $owner): int
    {
        $options = $export->exportOptions();

        if (! $this->auth->allows($owner, $options->exportable)) {
            throw ExportException::forbidden();
        }

        $exportable = $this->registry->get($options->exportable);
        $plan = ExportPlan::for($exportable, $options);
        $previous = Model::preventsLazyLoading();
        Model::preventLazyLoading();

        try {
            return $this->writer->store(
                $options->format,
                $options->title ?? (string) __($exportable->title()),
                $plan->headings(),
                $plan->numeric(),
                $this->builder->rows($plan, $this->builder->query($plan)),
                (string) $export->disk,
                (string) $export->path,
            );
        } finally {
            Model::preventLazyLoading($previous);
        }
    }
}
