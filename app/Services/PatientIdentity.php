<?php

namespace App\Services;

use App\Exceptions\PhoneOnFile;
use App\Models\Patient;
use App\Support\NigerianPhone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Issues NHC IDs and looks after the phone on file. Callers must have confirmed
 * an OTP to the number first.
 */
class PatientIdentity
{
    private const ATTEMPTS = 3;

    /** Someone with their own phone. A recycled number gets the next free suffix. */
    public function registerAccountHolder(string $phone, array $details): Patient
    {
        $number = NigerianPhone::normalise($phone);

        if (Patient::withTrashed()->where('phone', $number)->exists()) {
            throw new PhoneOnFile;
        }

        return $this->issue($number, $details, function (Patient $patient) use ($number) {
            $patient->phone = $number;
            $patient->phone_verified_at = now();
            $patient->save();
            $patient->phones()->create(['phone' => $number, 'verified_at' => now()]);
        });
    }

    /** Someone under a guardian's ID, with no phone of their own. */
    public function registerDependant(Patient $guardian, array $details): Patient
    {
        return $this->issue($guardian->nhc_base, $details, function (Patient $patient) use ($guardian) {
            $patient->guardian_id = $guardian->id;
            $patient->save();
        });
    }

    /** The phone changes; the ID stays. The old number becomes free for its next owner. */
    public function changePhone(Patient $patient, string $phone): Patient
    {
        $number = NigerianPhone::normalise($phone);

        if (Patient::withTrashed()->where('phone', $number)->whereKeyNot($patient->id)->exists()) {
            throw new PhoneOnFile;
        }

        return DB::connection($patient->getConnectionName())->transaction(function () use ($patient, $number) {
            $patient->phones()->whereNull('replaced_at')->update(['replaced_at' => now()]);
            $patient->phone = $number;
            $patient->phone_verified_at = now();
            $patient->save();
            $patient->phones()->create(['phone' => $number, 'verified_at' => now()]);

            return $patient;
        });
    }

    /** By NHC ID or current phone. Past numbers match nobody. Finding someone gives no access. */
    public function find(string $idOrPhone): ?Patient
    {
        $value = strtoupper(trim($idOrPhone));

        if (str_starts_with($value, 'NHC-')) {
            return Patient::where('nhc_id', $value)->first();
        }

        return Patient::where('phone', NigerianPhone::normalise($value))->first();
    }

    private function issue(string $base, array $details, callable $finish): Patient
    {
        $connection = (new Patient)->getConnectionName();

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::connection($connection)->transaction(function () use ($base, $details, $finish) {
                    // withTrashed: an ID stays taken for ever.
                    $suffix = (int) Patient::withTrashed()->where('nhc_base', $base)->max('nhc_suffix') + 1;

                    $patient = new Patient($details);
                    $patient->nhc_base = $base;
                    $patient->nhc_suffix = $suffix;
                    $patient->nhc_id = 'NHC-'.$base.($suffix > 1 ? "-{$suffix}" : '');
                    $finish($patient);

                    return $patient;
                });
            } catch (UniqueConstraintViolationException $e) {
                // Two registrations took the same suffix, so try the next one.
                if ($attempt === self::ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }
}
