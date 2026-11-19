<?php

use App\Modules\ClinicalRecords\Http\PatientRecordsController;
use App\Modules\ClinicalRecords\Http\StaffClinicalController;
use App\Modules\ClinicalRecords\Http\StaffRecordsController;
use Illuminate\Support\Facades\Route;

// Staff, at one hospital. Reads go through ClinicalAccess; writes are stamped and never edited.
Route::middleware(['auth:sanctum', 'abilities:staff', 'feature:clinical_records'])
    ->prefix('staff/facilities/{facility}')
    ->group(function () {
        Route::get('visits', [StaffRecordsController::class, 'visits']);
        Route::get('prescriptions', [StaffRecordsController::class, 'prescriptions'])->middleware('feature:prescriptions');

        Route::prefix('patients/{nhcId}/records')->where(['nhcId' => 'NHC-[0-9-]+'])->group(function () {
            Route::get('/', [StaffRecordsController::class, 'show']);
            Route::get('summary', [StaffRecordsController::class, 'summary']);
            Route::post('emergency-access', [StaffRecordsController::class, 'emergency'])->middleware('throttle:10,60');

            Route::controller(StaffClinicalController::class)->group(function () {
                Route::post('visits', 'openVisit');
                Route::post('visits/{visitId}/close', 'closeVisit');
                Route::post('visits/{visitId}/vitals', 'vitals');
                Route::post('visits/{visitId}/notes', 'note');
                Route::post('diagnoses', 'diagnosis');
                Route::post('diagnoses/{id}/resolve', 'resolveDiagnosis');
                Route::post('allergies', 'allergy');
                Route::post('allergies/{id}', 'updateAllergy');
                Route::post('facts', 'fact');
                Route::post('vaccinations', 'vaccination');
                Route::post('{kind}/{id}/entered-in-error', 'void')->where('kind', 'vitals|notes|diagnoses|allergies|prescriptions|vaccinations|facts');

                Route::middleware('feature:prescriptions')->group(function () {
                    Route::post('visits/{visitId}/prescriptions', 'prescribe');
                    Route::post('prescriptions/{id}/stop', 'stopPrescription');
                    Route::post('prescriptions/{id}/dispense', 'dispense');
                });
            });
        });
    });

// The patient, in the Hospify Citizen app: everything, from every hospital.
Route::middleware(['auth:sanctum', 'abilities:patient'])
    ->prefix('patient/records')
    ->controller(PatientRecordsController::class)
    ->group(function () {
        Route::get('/', 'index');
        Route::get('summary', 'summary');
        Route::post('allergies', 'addAllergy');
        Route::get('access', 'access');
    });
