<?php

use App\Modules\RecordSharing\Http\PatientRecordRequestController;
use App\Modules\RecordSharing\Http\StaffRecordRequestController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'abilities:staff', 'feature:record_sharing'])
    ->prefix('staff/facilities/{facility}/record-requests')
    ->controller(StaffRecordRequestController::class)
    ->group(function () {
        Route::get('hospitals', 'hospitals');
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('{id}', 'show');
        Route::post('{id}/consent-code', 'consentCode');
        Route::post('{id}/consent', 'consent');
        Route::post('{id}/cancel', 'cancel');
        Route::post('{id}/release', 'release');
        Route::post('{id}/decline', 'decline');
    });

Route::middleware(['auth:sanctum', 'abilities:patient'])
    ->prefix('patient/record-requests')
    ->controller(PatientRecordRequestController::class)
    ->group(function () {
        Route::get('/', 'index');
        Route::post('{id}/approve', 'approve');
        Route::post('{id}/decline', 'decline');
        Route::post('{id}/withdraw', 'withdraw');
    });
