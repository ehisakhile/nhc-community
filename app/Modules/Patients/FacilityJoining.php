<?php

namespace App\Modules\Patients;

use App\Models\AuditLog;
use App\Models\Concerns\FacilityScope;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\PatientFacilityLink;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * A patient becomes a patient of a hospital at that hospital's front desk, with
 * a code from their phone as consent. Patients can't add hospitals themselves.
 * Joining shares their profile with that hospital, nothing more.
 */
class FacilityJoining
{
    public function link(Patient $patient, Facility $facility, User $staff, ?string $ip = null): PatientFacilityLink
    {
        $link = $this->ownLinks($patient)->firstWhere('facility_id', $facility->id);
        if ($link) {
            return $link;
        }

        $link = new PatientFacilityLink([
            'patient_id' => $patient->id,
            'facility_id' => $facility->id,
            'joined_via' => 'front_desk_otp',
            'confirmed_at' => now(),
        ]);
        $link->save();

        AuditLog::create([
            'actor_type' => 'staff', 'actor_id' => $staff->id, 'patient_id' => $patient->id,
            'facility_id' => $facility->id, 'action' => 'patient.joined_facility',
            'ip_address' => $ip, 'occurred_at' => now(),
        ]);

        return $link;
    }

    /** The patient's own links across every hospital. The only place outside a hospital that lifts the scope. */
    public function ownLinks(Patient $patient): Collection
    {
        return PatientFacilityLink::withoutGlobalScope(FacilityScope::class)
            ->where('patient_id', $patient->id)
            ->get();
    }
}
