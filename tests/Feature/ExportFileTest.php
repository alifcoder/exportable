<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Feature;

use Alif\Export\Entities\DataExport;
use Alif\Export\Enums\ExportStatus;
use Alif\Export\Helpers\ExportFile;
use Alif\Export\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

final class ExportFileTest extends TestCase
{
    private ExportFile $files;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->files = new ExportFile;
    }

    /** @param array<string, mixed> $attrs */
    private function export(array $attrs = []): DataExport
    {
        return new DataExport($attrs + ['status' => ExportStatus::COMPLETED, 'disk' => 'local', 'path' => 'e/a.csv', 'expires_at' => now()->addHour()]);
    }

    public function test_expiry(): void
    {
        $this->assertFalse($this->files->isExpired($this->export(['expires_at' => null])));
        $this->assertFalse($this->files->isExpired($this->export(['expires_at' => now()->addSecond()])));
        $this->assertTrue($this->files->isExpired($this->export(['expires_at' => now()->subSecond()])));
    }

    public function test_downloadable_requires_completed_unexpired_and_a_recorded_file(): void
    {
        $this->assertTrue($this->files->isDownloadable($this->export()));
        $this->assertTrue($this->files->isDownloadable($this->export(['expires_at' => null])));
        $this->assertFalse($this->files->isDownloadable($this->export(['expires_at' => now()->subMinute()])));
        $this->assertFalse($this->files->isDownloadable($this->export(['disk' => null])));
        $this->assertFalse($this->files->isDownloadable($this->export(['path' => null])));
    }

    /** @return array<string, array{ExportStatus}> */
    public static function otherStatuses(): array
    {
        return ['pending' => [ExportStatus::PENDING], 'processing' => [ExportStatus::PROCESSING], 'failed' => [ExportStatus::FAILED]];
    }

    #[DataProvider('otherStatuses')]
    public function test_only_completed_exports_are_downloadable(ExportStatus $status): void
    {
        $this->assertFalse($this->files->isDownloadable($this->export(['status' => $status])));
    }

    public function test_exists_checks_the_disk_and_is_false_without_a_location(): void
    {
        $this->assertFalse($this->files->exists($this->export()));
        Storage::disk('local')->put('e/a.csv', 'x');
        $this->assertTrue($this->files->exists($this->export()));
        $this->assertFalse($this->files->exists($this->export(['disk' => null])));
        $this->assertFalse($this->files->exists($this->export(['path' => null])));
    }

    public function test_delete_removes_only_the_recorded_file(): void
    {
        Storage::disk('local')->put('e/a.csv', 'x');
        Storage::disk('local')->put('e/b.csv', 'x');

        $this->files->delete($this->export());

        Storage::disk('local')->assertMissing('e/a.csv');
        Storage::disk('local')->assertExists('e/b.csv');
    }

    public function test_delete_is_a_no_op_without_a_location_or_when_the_file_is_already_gone(): void
    {
        Storage::disk('local')->put('e/b.csv', 'x');

        $this->files->delete($this->export(['disk' => null]));
        $this->files->delete($this->export(['path' => null]));
        $this->files->delete($this->export());

        Storage::disk('local')->assertExists('e/b.csv');
    }
}
