<?php

use App\Modules\Messages\Http\PatientMessageController;
use App\Modules\Messages\Http\StaffMessageController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'abilities:patient'])->prefix('patient/threads')->controller(PatientMessageController::class)->group(function () {
    Route::get('/', 'index');
    Route::post('/', 'store')->middleware('throttle:20,60');
    Route::get('{id}', 'show');
    Route::post('{id}/messages', 'reply')->middleware('throttle:60,60');
});

Route::middleware(['auth:sanctum', 'abilities:staff', 'feature:messaging'])
    ->prefix('staff/facilities/{facility}/threads')
    ->controller(StaffMessageController::class)
    ->group(function () {
        Route::get('/', 'index');
        Route::get('{id}', 'show');
        Route::post('{id}/messages', 'reply');
        Route::post('{id}/{action}', 'close')->whereIn('action', ['close', 'reopen']);
    });
