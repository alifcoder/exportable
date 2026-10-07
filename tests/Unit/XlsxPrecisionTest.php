<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Unit;

use Alif\Export\Enums\ExportFormat;
use Alif\Export\Helpers\ExportWriter;
use Alif\Export\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class XlsxPrecisionTest extends TestCase
{
    public function test_non_numeric_int_is_exact_text_and_numeric_small_int_stays_numeric(): void
    {
        Storage::fake('local');

        app(ExportWriter::class)->store(
            ExportFormat::XLSX,
            't',
            ['id', 'qty'],
            [false, true],
            [[12345678901234567, 5]],
            'local',
            'x.xlsx',
        );

        $sheet = IOFactory::load(Storage::disk('local')->path('x.xlsx'))->getActiveSheet();

        $text = $sheet->getCell('A2');
        $this->assertContains($text->getDataType(), [DataType::TYPE_STRING, DataType::TYPE_INLINE]); // text, never a formula or number
        $this->assertSame('12345678901234567', (string) $text->getValue());

        $number = $sheet->getCell('B2');
        $this->assertSame(DataType::TYPE_NUMERIC, $number->getDataType());
        $this->assertEquals(5, $number->getValue());
    }
}
