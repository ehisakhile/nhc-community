<?php

namespace App\Modules\HospifyLink\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PatientProfileController extends Controller
{
    public function show(Request $request)
    {
        $patient = $request->user();

        return [
            'nhc_id' => $patient->nhc_id,
            'first_name' => $patient->first_name,
            'last_name' => $patient->last_name,
            'other_names' => $patient->other_names,
            'date_of_birth' => $patient->date_of_birth?->toDateString(),
            'gender' => $patient->gender,
            'phone' => $patient->phone ? '0'.$patient->phone : null,
            'email' => $patient->email,
            'hospify_linked' => (bool) $patient->hospify_ref,
            'dependants' => $patient->dependants()->get(['nhc_id', 'first_name', 'last_name']),
        ];
    }
}
