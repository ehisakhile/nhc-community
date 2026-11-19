<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A facility's NIPOST building postcode, from where it is. NHC has no NIPOST
 * key, so it asks Hospify (GET /nhc/postcode), signed with NHC_EVENTS_SECRET.
 */
class HospifyPostcode
{
    /** @return array{postcode: string, display: string}|null */
    public static function at(?float $lat, ?float $lng): ?array
    {
        $secret = (string) config('services.hospify.events_secret');
        if ($lat === null || $lng === null || $secret === '') {
            return null;
        }
        $payload = sprintf('%.6F,%.6F', $lat, $lng);
        try {
            $r = Http::baseUrl(rtrim(config('services.hospify.api_url'), '/'))->timeout(8)->acceptJson()
                ->withHeaders(['X-NHC-Signature' => hash_hmac('sha256', $payload, $secret)])
                ->get('/nhc/postcode', ['lat' => sprintf('%.6F', $lat), 'lng' => sprintf('%.6F', $lng)]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return $r->successful() && $r->json('postcode') ? ['postcode' => $r->json('postcode'), 'display' => $r->json('display') ?: $r->json('postcode')] : null;
    }
}
