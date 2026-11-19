<?php

namespace App\Modules\RecordSharing;

use App\Modules\Patients\PatientProfile;

final class RecordRequestView
{
    public static function of(RecordRequest $r, bool $withPatient = true): array
    {
        $labels = config('nhc.record_types');
        $terms = fn (?array $types, ?string $access, ?int $days) => $types === null ? null : [
            'record_types' => $types,
            'record_type_labels' => array_map(fn ($t) => $labels[$t] ?? $t, $types),
            'access_type' => $access,
            'duration_days' => $days,
        ];

        return [
            'id' => $r->id,
            'status' => $r->effectiveStatus(),
            'patient' => $withPatient ? PatientProfile::of($r->patient) : ['nhc_id' => $r->patient->nhc_id],
            'requesting_hospital' => ['id' => $r->requester->id, 'name' => $r->requester->name],
            'holding_hospital' => ['id' => $r->holder->id, 'name' => $r->holder->name],
            'requested_by' => $r->requestedBy?->name,
            'reason' => $r->reason,
            'requested' => $terms($r->record_types, $r->access_type, $r->duration_days),
            'granted' => $terms($r->granted_record_types, $r->granted_access_type, $r->granted_duration_days),
            'declined_by' => $r->declined_by,
            'decline_reason' => $r->decline_reason,
            'patient_decided_at' => $r->patient_decided_at?->format(DATE_ATOM),
            'released_at' => $r->released_at?->format(DATE_ATOM),
            'expires_at' => $r->expires_at?->format(DATE_ATOM),
            'created_at' => $r->created_at?->format(DATE_ATOM),
        ];
    }
}
