<?php

namespace App\Modules\Patients\Http;

use App\Exceptions\PhoneOnFile;
use App\Http\Controllers\Controller;
use App\Mail\NhcWelcome;
use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\FacilityMembership;
use App\Models\Patient;
use App\Models\PatientIdentityDocument;
use App\Modules\Patients\FacilityJoining;
use App\Services\OneTimeCodes;
use App\Services\PatientIdentity;
use App\Services\Termii;
use App\Support\NigerianPhone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Patients are onboarded at a partner hospital, in person. The front desk checks
 * one government ID; a code texted to the patient's phone proves the number is
 * theirs and is their consent to join this hospital. Linking to Hospify happens
 * later, by the patient, in the Citizen app (see Hospify\LinkController).
 */
class RegistrationController extends Controller
{
    public function start(Request $request, Facility $facility, OneTimeCodes $codes, Termii $sms)
    {
        $this->authorizeOnboarding($request, $facility);

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'id_type' => ['required', Rule::in(array_keys(config('nhc.id_documents')))],
            'id_number' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'other_names' => ['nullable', 'string', 'max:100'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'gender' => ['required', Rule::in(['female', 'male'])],
        ]);

        try {
            $phone = NigerianPhone::normalise($data['phone']);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        $idNumber = PatientIdentityDocument::clean($data['id_number']);
        abort_unless(
            preg_match(config("nhc.id_documents.{$data['id_type']}.pattern"), $idNumber),
            422, 'That '.config("nhc.id_documents.{$data['id_type']}.label").' number doesn\'t look right.',
        );

        // One ID document, one NHC ID. If it's on someone else's record, stop here.
        $owner = PatientIdentityDocument::where('type', $data['id_type'])
            ->where('number_hash', PatientIdentityDocument::hashNumber($data['id_type'], $idNumber))
            ->first()?->patient_id;
        $phoneOwner = Patient::where('phone', $phone)->value('id');
        abort_if($owner && $owner !== $phoneOwner, 422,
            'This ID is already on another NHC record. Search for the patient by NHC ID instead.');

        $codes->throttle('onboard', $phone);

        [$record, $plain] = $codes->issue('onboard', 'sms', $request->user(), [
            'facility_id' => $facility->id,
            'phone' => $phone,
            'id_number' => $idNumber,
            'email' => isset($data['email']) ? Str::lower(trim($data['email'])) : null,
        ] + $data);

        $sms->send($phone, "{$plain} is your NHC code to register at {$facility->name}. Only give it to the hospital staff in front of you.");

        return response()->json(['challenge' => $record->id, 'expires_in' => OneTimeCodes::TTL_MINUTES * 60]);
    }

    public function verify(Request $request, Facility $facility, OneTimeCodes $codes, PatientIdentity $identity, FacilityJoining $joining)
    {
        $staff = $request->user();
        $this->authorizeOnboarding($request, $facility);
        $data = $request->validate(['challenge' => ['required', 'string'], 'code' => ['required', 'string', 'max:10']]);

        $code = $codes->verify($data['challenge'], $data['code'], 'onboard');
        $details = $code->payload;

        // The code only works for the same staff member at the same hospital.
        abort_unless($code->subject_id === $staff->id && $details['facility_id'] === $facility->id, 422,
            'That code is wrong or has expired.');

        $patient = Patient::where('phone', $details['phone'])->first();
        $existing = (bool) $patient;

        try {
            $patient = DB::connection((new Patient)->getConnectionName())->transaction(function () use ($patient, $details, $identity, $staff, $facility) {
                if (! $patient) {
                    $patient = $identity->registerAccountHolder($details['phone'], array_intersect_key($details, array_flip([
                        'first_name', 'last_name', 'other_names', 'date_of_birth', 'gender',
                    ])));
                }

                if ($details['email'] && ! $patient->email && ! Patient::withTrashed()->where('email', $details['email'])->exists()) {
                    $patient->email = $details['email'];
                    $patient->save();
                }

                $hash = PatientIdentityDocument::hashNumber($details['id_type'], $details['id_number']);
                $document = PatientIdentityDocument::firstOrCreate(
                    ['type' => $details['id_type'], 'number_hash' => $hash],
                    [
                        'patient_id' => $patient->id, 'number' => $details['id_number'],
                        'last_four' => substr($details['id_number'], -4), 'facility_id' => $facility->id,
                        'checked_by' => $staff->id, 'checked_at' => now(),
                    ],
                );
                abort_if($document->patient_id !== $patient->id, 422, 'This ID is already on another NHC record.');

                return $patient;
            });
        } catch (PhoneOnFile) {
            abort(422, 'This number belongs to a closed NHC record. Contact NHC support.');
        }

        $joining->link($patient, $facility, $staff, $request->ip());

        if (! $existing) {
            AuditLog::create([
                'actor_type' => 'staff', 'actor_id' => $staff->id, 'facility_id' => $facility->id,
                'patient_id' => $patient->id, 'action' => 'patient.registered',
                'ip_address' => $request->ip(), 'occurred_at' => now(),
            ]);
        }

        if (! $existing && $patient->email) {
            Mail::to($patient->email)->send(new NhcWelcome($patient->first_name));
        }

        return response()->json([
            'nhc_id' => $patient->nhc_id,
            'already_registered' => $existing,
            'name' => trim("{$patient->first_name} {$patient->last_name}"),
            // For the printed slip: how they get the app working.
            'linked_to_hospify' => (bool) $patient->hospify_ref,
            'next_step' => 'Get the Hospify Citizen app, tap "Link my NHC record" and enter the code texted to this phone.',
        ], $existing ? 200 : 201);
    }

    private function authorizeOnboarding(Request $request, Facility $facility): void
    {
        abort_unless($facility->status === 'active', 422, "This hospital isn't live on NHC yet.");
        abort_unless(
            FacilityMembership::activeFor($request->user(), $facility)?->permits('patients.onboard'),
            403, "You can't register patients at this hospital.",
        );
    }
}
