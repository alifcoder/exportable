<?php

declare(strict_types=1);

namespace Alif\Export\Tests;

use Alif\Export\DTO\Export\ExportColumn;
use Alif\Export\DTO\Export\ExportCreateDTO;
use Alif\Export\DTO\Export\PreparedQuery;
use Alif\Export\Enums\ExportFormatEnum;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Tests\Fixtures\Doc;
use Alif\Export\Tests\Fixtures\DocResource;

final class ExportCreateDTOTest extends TestCase
{
    public function test_it_survives_the_job_payload(): void
    {
        $dto = new ExportCreateDTO(
            ExportFormatEnum::CSV,
            [new ExportColumn('number', 'Number', null, [])],
            new PreparedQuery(null, 'select 1', []),
            Doc::class,
            DocResource::class,
            ['lines'],
        );

        $this->assertEquals($dto, ExportCreateDTO::fromArray($dto->toArray()));
    }

    public function test_a_payload_without_model_is_refused(): void
    {
        $this->expectException(ExportException::class);

        ExportCreateDTO::fromArray(['format' => 'csv', 'columns' => [], 'query' => [], 'rows' => []]);
    }
}
