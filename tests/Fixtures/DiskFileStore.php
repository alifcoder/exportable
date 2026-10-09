<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Fixtures;

use Alif\Export\Contracts\ExportFileStore;
use Alif\Export\DTO\Export\ExportTask;
use Alif\Export\Exceptions\ExportException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Test host store: keeps files on the default disk; the file id is the path. */
final class DiskFileStore implements ExportFileStore
{
    public static bool $failPut = false;

    public function put(ExportTask $task, string $localPath, string $fileName, string $mimeType): string
    {
        if (self::$failPut) {
            throw ExportException::storageWriteFailed('test failure');
        }

        $path = 'exports/'.Str::uuid().'/'.$fileName;
        $stream = fopen($localPath, 'rb');
        Storage::put($path, $stream);
        fclose($stream);

        return $path;
    }
}
