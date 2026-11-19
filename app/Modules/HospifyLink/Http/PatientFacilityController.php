<?php

namespace App\Modules\HospifyLink\Http;

use App\Http\Controllers\Controller;
use App\Models\Facility;
use App\Modules\Patients\FacilityJoining;
use Illuminate\Http\Request;

/** The hospitals a patient has been onboarded at. Joining happens at the front desk. */
class PatientFacilityController extends Controller
{
    public function index(Request $request, FacilityJoining $joining)
    {
        $links = $joining->ownLinks($request->user());
        $facilities = Facility::whereIn('id', $links->pluck('facility_id'))->get(['id', 'name', 'type', 'state', 'lga'])->keyBy('id');

        return $links->map(fn ($link) => [
            'facility' => $facilities->get($link->facility_id),
            'joined_at' => $link->confirmed_at,
        ])->values();
    }
}
