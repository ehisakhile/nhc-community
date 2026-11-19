<?php

namespace App\Modules\HospifyLink\Http;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Services\OneTimeCodes;
use App\Services\Termii;
use App\Support\NigerianPhone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * "Link my NHC record" in the Citizen app. Hospify's API calls these on the
 * signed-in person's behalf. The phone on the NHC record decides, never an email,
 * so it works however they joined Hospify (email, Google or Apple).
 *
 * One NHC ID links to one Hospify account, and one account to one NHC ID.
 */
class LinkController extends Controller
{
    /**
     * Shortcut: Hospify asks whether an NHC record carries this account's
     * verified email. Answers with a masked ID only; linking still needs the SMS code.
     */
    public function suggest(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);

        $patient = Patient::where('email', Str::lower(trim($data['email'])))
            ->whereNull('hospify_ref')->whereNull('guardian_id')->whereNotNull('phone')->first();

        return response()->json($patient
            ? ['found' => true, 'nhc_id_masked' => self::mask($patient->nhc_id), 'phone_hint' => self::phoneHint($patient->phone)]
            : ['found' => false]);
    }

    /** Texts a code to the phone on the record found by NHC ID, phone or (after suggest) email. */
    public function start(Request $request, OneTimeCodes $codes, Termii $sms)
    {
        $data = $request->validate([
            'lookup' => ['required', 'string', 'max:255'],
            'hospify_ref' => ['required', 'string', 'max:64'],
        ]);

        $codes->throttle('link', $data['lookup']);
        $codes->throttle('link-account', $data['hospify_ref']);

        $patient = $this->find($data['lookup']);
        $reply = ['message' => "If that matches an NHC record, we've texted a code to the phone on it."];

        if (! $patient) {
            return response()->json(['challenge' => $codes->decoy()] + $reply);
        }

        [$record, $plain] = $codes->issue('link', 'sms', $patient, ['hospify_ref' => $data['hospify_ref']]);
        $sms->send($patient->phone, "{$plain} is your code to link your NHC record to Hospify. Don't share it with anyone.");

        return response()->json(['challenge' => $record->id] + $reply);
    }

    public function confirm(Request $request, OneTimeCodes $codes)
    {
        $data = $request->validate([
            'challenge' => ['required', 'string'],
            'code' => ['required', 'string', 'max:10'],
            'hospify_ref' => ['required', 'string', 'max:64'],
        ]);

        $code = $codes->verify($data['challenge'], $data['code'], 'link');
        abort_unless($code->payload['hospify_ref'] === $data['hospify_ref'] && $code->subject instanceof Patient,
            422, 'That code is wrong or has expired.');

        $patient = $code->subject;

        $other = Patient::where('hospify_ref', $data['hospify_ref'])->whereKeyNot($patient->id)->value('nhc_id');
        abort_if($other, 409, 'This Hospify account is already linked to another NHC record.');

        // Linking from a second Hospify account moves the link; the SMS code is the proof.
        $previous = $patient->hospify_ref;

        DB::connection($patient->getConnectionName())->transaction(function () use ($patient, $data, $previous, $request) {
            $patient->hospify_ref = $data['hospify_ref'];
            $patient->hospify_linked_at = now();
            $patient->save();

            AuditLog::create([
                'actor_type' => 'patient', 'actor_id' => $patient->id, 'patient_id' => $patient->id,
                'action' => $previous ? 'hospify.link_moved' : 'hospify.linked',
                'ip_address' => $request->ip(), 'occurred_at' => now(),
            ]);
        });

        return response()->json([
            'nhc_id' => $patient->nhc_id,
            'first_name' => $patient->first_name,
            // Hospify clears the NHC ID from this account, if any.
            'previous_hospify_ref' => $previous !== $data['hospify_ref'] ? $previous : null,
        ]);
    }

    /** The Hospify account was deleted or the person unlinked in the app. */
    public function remove(Request $request)
    {
        $data = $request->validate(['hospify_ref' => ['required', 'string', 'max:64']]);

        $patient = Patient::where('hospify_ref', $data['hospify_ref'])->first();
        if ($patient) {
            $patient->hospify_ref = null;
            $patient->hospify_linked_at = null;
            $patient->save();
            $patient->tokens()->delete();

            AuditLog::create([
                'actor_type' => 'hospify', 'patient_id' => $patient->id, 'action' => 'hospify.unlinked',
                'ip_address' => $request->ip(), 'occurred_at' => now(),
            ]);
        }

        return response()->noContent();
    }

    /** Account holders only: dependants appear under their guardian. */
    private function find(string $lookup): ?Patient
    {
        $value = trim($lookup);
        $query = Patient::query()->whereNull('guardian_id')->whereNotNull('phone');

        if (str_contains($value, '@')) {
            return $query->where('email', Str::lower($value))->first();
        }
        if (str_starts_with(strtoupper($value), 'NHC-')) {
            return $query->where('nhc_id', strtoupper($value))->first();
        }

        try {
            return $query->where('phone', NigerianPhone::normalise($value))->first();
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private static function mask(string $nhcId): string
    {
        return substr($nhcId, 0, 7).'•••'.substr($nhcId, -4);
    }

    private static function phoneHint(string $phone): string
    {
        return '0'.substr($phone, 0, 3).' ••• '.substr($phone, -2);
    }
}
