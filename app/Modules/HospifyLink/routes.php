<?php

use App\Modules\HospifyLink\Http\HospifyService;
use App\Modules\HospifyLink\Http\LinkController;
use App\Modules\HospifyLink\Http\PatientFacilityController;
use App\Modules\HospifyLink\Http\PatientProfileController;
use App\Modules\HospifyLink\Http\StaffLookupController;
use Illuminate\Support\Facades\Route;

// Server to server: Hospify's API, on behalf of a signed-in Citizen user.
Route::prefix('hospify/links')->middleware('throttle:60,1')->group(function () {
    Route::post('suggest', [LinkController::class, 'suggest'])->middleware(HospifyService::class.':links:suggest');
    Route::post('start', [LinkController::class, 'start'])->middleware(HospifyService::class.':links:start');
    Route::post('confirm', [LinkController::class, 'confirm'])->middleware(HospifyService::class.':links:confirm');
    Route::post('remove', [LinkController::class, 'remove'])->middleware(HospifyService::class.':links:remove');
});

// Server to server: Hospify Pro sign-in for NHC staff who have no Hospify account yet.
Route::post('hospify/staff/lookup', [StaffLookupController::class, 'lookup'])
    ->middleware(['throttle:60,1', HospifyService::class.':staff:lookup']);

Route::middleware(['auth:sanctum', 'abilities:patient'])->prefix('patient')->group(function () {
    Route::get('me', [PatientProfileController::class, 'show']);
    Route::get('facilities', [PatientFacilityController::class, 'index']);
});
