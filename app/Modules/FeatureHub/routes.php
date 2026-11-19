<?php

use App\Modules\FeatureHub\FeatureController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'abilities:staff'])->prefix('staff/facilities/{facility}/features')->group(function () {
    Route::get('/', [FeatureController::class, 'index']);
    Route::put('{key}', [FeatureController::class, 'update']);
});
