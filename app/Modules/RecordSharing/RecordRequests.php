<?php

namespace App\Modules\RecordSharing;

use App\Models\AuditLog;
use App\Models\Concerns\FacilityScope;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\PatientFacilityLink;
use App\Models\User;
use App\Modules\FeatureHub\Features;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Moving records between hospitals. Hospital A asks; the patient approves
 * (in the Citizen app or with a code at A's desk); hospital B releases. Each
 * step can narrow the request (fewer record types, view instead of copy,
 * fewer days) but never widen it. Every step is in the audit log.
 */
class RecordRequests
{
    public function __construct(private Features $features) {}

    public function create(Facility $requester, Facility $holder, Patient $patient, User $by, array $terms, string $reason): RecordRequest
    {
        if ($requester->id === $holder->id) {
            throw new HttpException(422, 'Ask another hospital: this one already holds its own records.');
        }
        if ($holder->status !== 'active' || ! $this->features->enabled($holder, 'record_sharing')) {
            throw new HttpException(422, "{$holder->name} isn't sharing records on NHC.");
        }
        if (! $this->isPatientAt($patient, $requester)) {
            throw new HttpException(422, 'Onboard the patient at your hospital first.');
        }
        if (RecordRequest::where(['patient_id' => $patient->id, 'requesting_facility_id' => $requester->id, 'holding_facility_id' => $holder->id])
            ->whereIn('status', RecordRequest::OPEN)->exists()) {
            throw new HttpException(409, 'There is already an open request for this patient to that hospital.');
        }

        $terms = $this->validTerms($terms);
        $request = RecordRequest::create([
            'patient_id' => $patient->id,
            'requesting_facility_id' => $requester->id,
            'holding_facility_id' => $holder->id,
            'requested_by' => $by->id,
            'reason' => $reason,
            'record_types' => $terms['record_types'],
            'access_type' => $terms['access_type'],
            'duration_days' => $terms['duration_days'],
            'status' => 'awaiting_patient',
        ]);
        $this->log($request, 'staff', $by->id, $requester->id, 'record_request.created');

        return $request;
    }

    /** The patient decides. Approving may narrow; B sees the request only after this. */
    public function patientDecision(RecordRequest $r, bool $approve, array $narrowed, string $channel): RecordRequest
    {
        $this->expect($r, 'awaiting_patient');

        if (! $approve) {
            $r->update(['status' => 'declined', 'declined_by' => 'patient', 'patient_decided_at' => now(), 'patient_channel' => $channel]);
            $this->log($r, 'patient', $r->patient_id, null, 'record_request.patient_declined');

            return $r;
        }

        $granted = $this->narrow($this->requested($r), $narrowed);
        $r->fill([
            'granted_record_types' => $granted['record_types'],
            'granted_access_type' => $granted['access_type'],
            'granted_duration_days' => $granted['duration_days'],
            'patient_decided_at' => now(),
            'patient_channel' => $channel,
        ]);

        // Only now, with the patient's say-so, is it checked whether B has them at all.
        $r->status = $this->isPatientAt($r->patient, $r->holder) ? 'awaiting_hospital' : 'declined';
        if ($r->status === 'declined') {
            $r->declined_by = 'system';
            $r->decline_reason = "{$r->holder->name} has no records for this patient.";
        }
        $r->save();
        $this->log($r, 'patient', $r->patient_id, null, 'record_request.patient_approved');

        return $r;
    }

    /** Hospital B releases (possibly narrowing further) or declines. */
    public function hospitalDecision(RecordRequest $r, User $by, bool $release, array $narrowed, ?string $reason): RecordRequest
    {
        $this->expect($r, 'awaiting_hospital');

        if (! $release) {
            $r->update(['status' => 'declined', 'declined_by' => 'hospital', 'decline_reason' => $reason, 'released_by' => $by->id]);
            $this->log($r, 'staff', $by->id, $r->holding_facility_id, 'record_request.hospital_declined');

            return $r;
        }

        $granted = $this->narrow($this->granted($r), $narrowed);
        $r->update([
            'granted_record_types' => $granted['record_types'],
            'granted_access_type' => $granted['access_type'],
            'granted_duration_days' => $granted['duration_days'],
            'status' => 'approved',
            'released_by' => $by->id,
            'released_at' => now(),
            'expires_at' => $granted['access_type'] === 'view' ? now()->addDays($granted['duration_days']) : null,
        ]);
        $this->log($r, 'staff', $by->id, $r->holding_facility_id, 'record_request.released');

        return $r;
    }

    public function cancel(RecordRequest $r, User $by): RecordRequest
    {
        if (! in_array($r->status, RecordRequest::OPEN, true)) {
            throw new HttpException(409, 'Only an open request can be cancelled.');
        }
        $r->update(['status' => 'cancelled']);
        $this->log($r, 'staff', $by->id, $r->requesting_facility_id, 'record_request.cancelled');

        return $r;
    }

    /** The patient takes view access back. A copy already made can't be taken back. */
    public function withdraw(RecordRequest $r): RecordRequest
    {
        if (! $r->isActiveGrant()) {
            throw new HttpException(409, 'Only current access can be withdrawn.');
        }
        if ($r->granted_access_type === 'copy') {
            throw new HttpException(409, 'A copy has been given to the hospital and can\'t be taken back. You can ask the hospital to delete it.');
        }
        $r->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
        $this->log($r, 'patient', $r->patient_id, null, 'record_request.withdrawn');

        return $r;
    }

    /** For the records module: what hospital A may currently read of the patient's records at B. */
    public function grantsFor(Facility $reader, Patient $patient): Collection
    {
        return RecordRequest::where(['requesting_facility_id' => $reader->id, 'patient_id' => $patient->id, 'status' => 'approved'])
            ->get()->filter->isActiveGrant()->values();
    }

    // --- Terms ---------------------------------------------------------------

    /** @return array{record_types: list<string>, access_type: string, duration_days: ?int} */
    public function validTerms(array $t): array
    {
        $types = array_values(array_unique($t['record_types'] ?? []));
        $known = array_keys(config('nhc.record_types'));
        if ($types === [] || array_diff($types, $known)) {
            throw new HttpException(422, 'Choose which records: '.implode(', ', $known).'.');
        }
        $access = $t['access_type'] ?? 'view';
        if (! in_array($access, ['view', 'copy'], true)) {
            throw new HttpException(422, 'Access is either "view" (for a time) or "copy".');
        }
        $days = $access === 'view' ? (int) ($t['duration_days'] ?? config('nhc.record_access.default_days')) : null;
        if ($access === 'view' && ($days < 1 || $days > config('nhc.record_access.max_days'))) {
            throw new HttpException(422, 'View access lasts between 1 and '.config('nhc.record_access.max_days').' days.');
        }

        return ['record_types' => $types, 'access_type' => $access, 'duration_days' => $days];
    }

    /** Fewer types, view instead of copy, fewer days. Anything wider is refused. */
    private function narrow(array $current, array $proposed): array
    {
        $access = $proposed['access_type'] ?? $current['access_type'];
        $days = $proposed['duration_days'] ?? match (true) {
            $access === 'copy' => null,
            $current['access_type'] === 'view' => $current['duration_days'],
            default => config('nhc.record_access.default_days'),  // copy narrowed to view
        };
        $next = $this->validTerms([
            'record_types' => $proposed['record_types'] ?? $current['record_types'],
            'access_type' => $access,
            'duration_days' => $days,
        ]);

        if (array_diff($next['record_types'], $current['record_types'])) {
            throw new HttpException(422, 'You can only remove record types, not add them.');
        }
        if ($current['access_type'] === 'view' && $next['access_type'] === 'copy') {
            throw new HttpException(422, 'Access to view can\'t be turned into a copy.');
        }
        if ($current['access_type'] === 'view' && $next['duration_days'] > $current['duration_days']) {
            throw new HttpException(422, "Access can last at most {$current['duration_days']} days.");
        }

        return $next;
    }

    private function requested(RecordRequest $r): array
    {
        return ['record_types' => $r->record_types, 'access_type' => $r->access_type, 'duration_days' => $r->duration_days];
    }

    private function granted(RecordRequest $r): array
    {
        return ['record_types' => $r->granted_record_types, 'access_type' => $r->granted_access_type, 'duration_days' => $r->granted_duration_days];
    }

    private function expect(RecordRequest $r, string $status): void
    {
        if ($r->status !== $status) {
            throw new HttpException(409, 'This request has already moved on.');
        }
    }

    private function isPatientAt(Patient $patient, Facility $facility): bool
    {
        return PatientFacilityLink::withoutGlobalScope(FacilityScope::class)
            ->where(['patient_id' => $patient->id, 'facility_id' => $facility->id])->exists();
    }

    private function log(RecordRequest $r, string $actorType, ?string $actorId, ?string $facilityId, string $action): void
    {
        AuditLog::create([
            'actor_type' => $actorType, 'actor_id' => $actorId, 'facility_id' => $facilityId, 'patient_id' => $r->patient_id,
            'action' => $action, 'target_type' => 'record_request', 'target_id' => $r->id, 'occurred_at' => now(),
        ]);
    }
}
