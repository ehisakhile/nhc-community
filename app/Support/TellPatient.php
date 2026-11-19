<?php

namespace App\Support;

use App\Models\Patient;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A push to the patient's Hospify Citizen app, through Hospify (NHC has no
 * push of its own). Only for patients who linked their Hospify account.
 * POST {HOSPIFY_API_URL}/nhc/patient-events, HMAC-SHA256 with NHC_EVENTS_SECRET.
 * Never raises: the change is saved either way and shows in the app.
 */
class TellPatient
{
    public static function send(Patient $patient, string $title, string $body, array $data = []): void
    {
        $secret = (string) config('services.hospify.events_secret');
        $ref = $patient->getRawOriginal('hospify_ref') ?? $patient->hospify_ref;
        if ($secret === '' || ! $ref) {
            return;
        }
        $payload = json_encode(['event' => 'patient.notify', 'hospify_ref' => $ref, 'title' => $title, 'body' => $body,
            'data' => ['type' => 'nhc', ...$data], 'at' => now()->toIso8601String()]);
        try {
            Http::baseUrl(rtrim(config('services.hospify.api_url'), '/'))->timeout(8)
                ->withHeaders(['X-NHC-Signature' => hash_hmac('sha256', $payload, $secret)])
                ->withBody($payload, 'application/json')->post('/nhc/patient-events');
        } catch (Throwable $e) {
            report($e);
        }
    }
}
