<?php

declare(strict_types=1);

namespace Alif\Export\Services\Actions\Export;

use Alif\Export\Contracts\ExportAuth;
use Alif\Export\Entities\DataExport;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportBuilder;
use Alif\Export\Helpers\ExportPlan;
use Alif\Export\Helpers\ExportRegistry;
use Alif\Export\Helpers\ExportWriter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;

/** Writes the file of a claimed export, signed in as its owner so the host filter's scope applies. */
final readonly class GenerateExport
{
    public function __construct(
        private ExportAuth $auth,
        private ExportRegistry $registry,
        private ExportBuilder $builder,
        private ExportWriter $writer,
    ) {}

    /**
     * @return int Rows written.
     *
     * @throws ExportException
     */
    public function __invoke(DataExport $export): int
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
        $dto = $export->exportDto();

        if (! $this->auth->allows($owner, $dto->exportable)) {
            throw ExportException::forbidden();
        }

        $exportable = $this->registry->get($dto->exportable);
        $plan = ExportPlan::for($exportable, $dto);
        $previous = Model::preventsLazyLoading();
        Model::preventLazyLoading();

        try {
            return $this->writer->store(
                $dto->format,
                $dto->title ?? (string) __($exportable->title()),
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
