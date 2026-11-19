<?php

namespace App\Modules\Patients;

use App\Models\Patient;

/**
 * The basic profile every hospital a patient joins can see. Never clinical
 * records: those move only with the patient's consent (Record sharing).
 */
final class PatientProfile
{
    public static function of(Patient $p, ?\DateTimeInterface $joinedAt = null): array
    {
        return [
            'nhc_id' => $p->nhc_id,
            'first_name' => $p->first_name,
            'last_name' => $p->last_name,
            'other_names' => $p->other_names,
            'date_of_birth' => $p->date_of_birth?->toDateString(),
            'gender' => $p->gender,
            'phone' => $p->phone ? '0'.$p->phone : null,
            'email' => $p->email,
            'joined_at' => $joinedAt?->format(DATE_ATOM),
        ];
    }

    /** Before the patient agrees to join: enough to be sure it's them, nothing more. */
    public static function preview(Patient $p): array
    {
        $mask = fn (?string $s) => $s ? mb_substr($s, 0, 1).str_repeat('•', max(mb_strlen($s) - 1, 2)) : null;

        return [
            'nhc_id' => $p->nhc_id,
            'name' => trim($mask($p->first_name).' '.$mask($p->last_name)),
            'birth_year' => $p->date_of_birth?->year,
            'phone_hint' => $p->phone ? '0'.substr($p->phone, 0, 3).' ••• '.substr($p->phone, -2) : null,
        ];
    }
}
