<?php

namespace App\Modules\ClinicalRecords\Http;

use App\Models\Concerns\FacilityScope;
use App\Models\Facility;
use App\Models\FacilityMembership;
use App\Models\Patient;
use App\Models\PatientFacilityLink;
use Illuminate\Http\Request;

/** Shared checks for staff working on a patient's record at one hospital. */
trait ClinicalStaff
{
    /** The staff member's active role here, holding at least one of the permissions. */
    protected function memberWith(Request $request, Facility $facility, array $anyOf, string $message = "Your role can't do that here."): FacilityMembership
    {
        abort_unless($facility->status === 'active', 422, "This hospital isn't live on NHC yet.");
        $member = FacilityMembership::activeFor($request->user(), $facility);
        abort_unless($member && collect($anyOf)->contains(fn ($p) => $member->permits($p)), 403, $message);

        return $member;
    }

    /** A patient who has joined this hospital. Anyone else is not found. */
    protected function patientHere(Facility $facility, string $nhcId): Patient
    {
        $link = PatientFacilityLink::withoutGlobalScope(FacilityScope::class)
            ->where('facility_id', $facility->id)
            ->whereHas('patient', fn ($p) => $p->where('nhc_id', strtoupper($nhcId)))
            ->with('patient')->first();
        abort_unless($link, 404, "This patient hasn't joined your hospital on NHC.");

        return $link->patient;
    }
}
