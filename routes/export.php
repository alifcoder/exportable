<?php

declare(strict_types=1);

use Alif\Export\Http\ExportController;
use Illuminate\Support\Facades\Route;

Route::get('definition', [ExportController::class, 'definition'])->name('export.definition');
Route::get('/', [ExportController::class, 'index'])->name('export.index');
Route::post('/', [ExportController::class, 'store'])->name('export.store');
Route::get('{export}', [ExportController::class, 'show'])->whereUuid('export')->name('export.show');
Route::delete('{export}', [ExportController::class, 'destroy'])->whereUuid('export')->name('export.destroy');
Route::get('{export}/download', [ExportController::class, 'download'])->whereUuid('export')->name('export.download');
Route::post('{export}/retry', [ExportController::class, 'retry'])->whereUuid('export')->name('export.retry');
