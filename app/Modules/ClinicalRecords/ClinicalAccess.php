<?php

namespace App\Modules\ClinicalRecords;

use App\Models\AuditLog;
use App\Models\Concerns\FacilityScope;
use App\Models\Facility;
use App\Models\FacilityMembership;
use App\Models\Patient;
use App\Modules\ClinicalRecords\Models\EmergencyAccess;
use App\Modules\RecordSharing\RecordRequests;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The only way to read the central health record. Works out what a reader may
 * see (which hospitals' rows, which record types, up to when), logs the read,
 * and builds the queries. Nothing else removes FacilityScope from a clinical model.
 *
 *  - The patient: everything, from every hospital, always.
 *  - Staff at the hospital that wrote it: by role (doctors and nurses all of it,
 *    pharmacists prescriptions and the summary, labs the summary).
 *  - Staff elsewhere: only what an approved record request grants, while it lasts.
 *    A copy covers rows written up to its release; a view, everything until it ends.
 *  - Emergency access: the summary from every hospital, for a day.
 */
class ClinicalAccess
{
    public const ALL = ['summary', 'consultations', 'prescriptions', 'lab_results', 'imaging', 'discharge_summaries', 'vaccinations'];

    public function __construct(private RecordRequests $requests) {}

    /** Record types a staff member may read at their own hospital. */
    public static function ownTypes(FacilityMembership $member): array
    {
        return match (true) {
            $member->permits('records.read') => self::ALL,
            $member->permits('prescriptions.read') => ['summary', 'prescriptions'],
            $member->permits('lab.read'), $member->permits('imaging.read') => ['summary'],
            default => [],
        };
    }

    public function forStaff(FacilityMembership $member, Facility $facility, Patient $patient): Reach
    {
        $reach = new Reach;
        $reach->allow($facility->id, self::ownTypes($member), via: 'own');

        if ($member->permits('records.read')) {
            foreach ($this->requests->grantsFor($facility, $patient) as $grant) {
                $reach->allow($grant->holding_facility_id, $grant->granted_record_types ?? [],
                    via: 'request:'.$grant->id,
                    before: $grant->granted_access_type === 'copy' ? $grant->released_at : null,
                    until: $grant->expires_at);
            }

            $emergency = EmergencyAccess::where(['patient_id' => $patient->id, 'facility_id' => $facility->id, 'user_id' => $member->user_id])
                ->where('expires_at', '>', now())->latest('expires_at')->first();
            if ($emergency) {
                $reach->allowEverywhere(['summary'], via: 'emergency:'.$emergency->id, until: $emergency->expires_at);
            }
        }

        return $reach;
    }

    public function forPatient(Patient $patient): Reach
    {
        return (new Reach)->allowEverywhere(self::ALL, via: 'patient');
    }

    /**
     * Rows of one model a reader may see for one record type, across hospitals.
     * Rows the patient added themselves (no hospital) count as summary.
     */
    public function query(Reach $reach, string $model, Patient $patient, string $type): Builder
    {
        /** @var Builder $q */
        $q = $model::query()->withoutGlobalScope(FacilityScope::class)->where('patient_id', $patient->id);

        if ($reach->everywhere($type)) {
            return $q;
        }

        $clauses = $reach->facilitiesFor($type);
        if (! $clauses) {
            return $q->whereRaw('1 = 0');
        }

        $table = (new $model)->getTable();

        return $q->where(function (Builder $w) use ($clauses, $type, $table) {
            foreach ($clauses as $facilityId => $before) {
                $w->orWhere(function (Builder $one) use ($facilityId, $before, $table) {
                    $one->where("$table.facility_id", $facilityId);
                    if ($before) {
                        $one->where("$table.created_at", '<=', $before);
                    }
                });
            }
            if ($type === 'summary') {
                $w->orWhereNull("$table.facility_id");
            }
        });
    }

    /** One row in the audit log per read, naming how access was given. */
    public function logRead(Reach $reach, Patient $patient, string $actorType, string $actorId, ?string $facilityId, ?string $ip): void
    {
        AuditLog::create([
            'actor_type' => $actorType, 'actor_id' => $actorId, 'facility_id' => $facilityId, 'patient_id' => $patient->id,
            'action' => 'records.viewed', 'reason' => $reach->describe(), 'ip_address' => $ip, 'occurred_at' => now(),
        ]);
    }

    /** A row this hospital wrote, for changing it (status, void). Never another hospital's. */
    public function ownRow(string $model, Facility $facility, Patient $patient, string $id): Model
    {
        return $model::query()->withoutGlobalScope(FacilityScope::class)
            ->where(['facility_id' => $facility->id, 'patient_id' => $patient->id])->findOrFail($id);
    }
}
