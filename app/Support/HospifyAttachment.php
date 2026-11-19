<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A short-lived link to a document the customer attached on Hospify (a
 * doctor's letter or request form). Hospify keeps the files private; NHC asks
 * for a fresh link each time staff open one (POST /nhc/attachment, signed).
 */
class HospifyAttachment
{
    public static function link(string $reference, int $index): string
    {
        $secret = (string) config('services.hospify.events_secret');
        $base = rtrim((string) config('services.hospify.api_url'), '/');
        abort_if($secret === '' || $base === '', 503, "Documents can't be opened right now.");
        $raw = json_encode(['reference' => $reference, 'index' => $index, 'ts' => time()]);
        try {
            $r = Http::timeout(15)->acceptJson()->withHeaders(['X-NHC-Signature' => hash_hmac('sha256', $raw, $secret)])
                ->withBody($raw, 'application/json')->post($base.'/nhc/attachment');
        } catch (ConnectionException) {
            throw new HttpException(503, "Couldn't reach Hospify. Try again in a moment.");
        }
        abort_unless($r->successful() && is_string($r->json('url')), $r->status() === 404 ? 404 : 502, "Couldn't open that document.");

        return $r->json('url');
    }
}
