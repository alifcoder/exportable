<?php

declare(strict_types=1);

use Alif\Export\Http\ExportController;
use Illuminate\Support\Facades\Route;

Route::get('exportables/{exportable}', [ExportController::class, 'definition'])->name('export.definition');
Route::post('/', [ExportController::class, 'store'])->name('export.store');
Route::get('{export}', [ExportController::class, 'show'])->whereUuid('export')->name('export.show');
Route::get('{export}/download', [ExportController::class, 'download'])->whereUuid('export')->name('export.download');
