<?php

declare(strict_types=1);

namespace Alif\Export\Tests\EndToEnd;

final class SmokeTest extends EndToEndTestCase
{
    public function test_a_document_with_lists_is_written_as_csv_and_announced_once(): void
    {
        $this->seedOrders(2, [], lines: 1, serials: 1);

        $task = $this->export(['format' => 'csv', 'columns' => [
            'number' => 'No',
            'lines' => ['sequence' => '#', 'serials' => ['imei' => 'IMEI']],
        ]]);

        $this->assertCount(1, $this->finished);
        $this->assertSame($task->id, $this->finished[0]->task->id);
        $this->assertSame($task->id.'.csv', $this->finished[0]->fileName);
        $this->assertSame(10, $this->finished[0]->rows);
        $this->assertStringContainsString('IMEI-2-1-1', $this->lastFile());
    }
}
