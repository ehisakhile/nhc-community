<?php

namespace App\Modules\Auth\Http;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Support\HospifyToken;
use Illuminate\Http\Request;

/**
 * Patients never sign in to NHC directly. The Citizen app asks Hospify for a
 * five-minute pass (signed with Hospify's key), and swaps it here for a short
 * NHC token. When it runs out, the app quietly swaps again; if the person signs
 * out of Hospify or loses their account, the swaps stop.
 */
class HospifySignInController extends Controller
{
    public function exchange(Request $request)
    {
        $data = $request->validate([
            'assertion' => ['required', 'string', 'max:4000'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        // A pass has no scope; service calls do, so one can't stand in for the other.
        $claims = HospifyToken::verify($data['assertion']);
        abort_if(isset($claims['scope']) || ! is_string($claims['sub'] ?? null) || ! is_string($claims['nhc_id'] ?? null),
            401, 'Please sign in to Hospify again.');

        // sub is the Hospify account's nhc_ref, stored when the person linked with an SMS code.
        $patient = Patient::where('nhc_id', $claims['nhc_id'])->where('hospify_ref', $claims['sub'])->first();
        abort_unless($patient, 401, 'This Hospify account is not linked to that NHC ID.');

        $minutes = config('services.hospify.patient_token_minutes');
        $patient->tokens()->where('expires_at', '<', now())->delete();

        return response()->json([
            'token' => $patient->createToken($data['device_name'] ?? 'Hospify Citizen', ['patient'], now()->addMinutes($minutes))->plainTextToken,
            'nhc_id' => $patient->nhc_id,
            'expires_in' => $minutes * 60,
        ]);
    }

    public function signOut(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
